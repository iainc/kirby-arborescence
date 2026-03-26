import fuzzysort from "fuzzysort";

const SEARCH_ROOT_ID = "__arborescence_search_root__";
const FEATURE_FLAG_MARKER = "\u2063";

let searchChildrenByParentId = Object.create(null);
let searchRecords = [];
let searchRecordsById = Object.create(null);

function buildSearchTree(parentId, keptIds, matchesById, subtreeRanks) {
  const items = [];

  for (const childId of searchChildrenByParentId[parentId] ?? []) {
    if (keptIds.has(childId) !== true) {
      continue;
    }

    const record = searchRecordsById[childId];
    if (!record) {
      continue;
    }

    const children = buildSearchTree(record.id, keptIds, matchesById, subtreeRanks);
    const match = matchesById[record.id] ?? null;
    items.push({
      _order: record.order ?? 0,
      _rank: subtreeRanks[record.id] ?? Number.POSITIVE_INFINITY,
      canChangeSlug: record.canChangeSlug === true,
      canChangeSort: record.canChangeSort === true,
      canChangeStatus: record.canChangeStatus === true,
      canChangeTitle: record.canChangeTitle === true,
      canCreate: record.canCreate === true,
      canDelete: record.canDelete === true,
      canDuplicate: record.canDuplicate === true,
      children,
      disabled: false,
      featureFlag: record.featureFlag ?? null,
      flagParts: match?.flagParts ?? null,
      hasChildren: children.length > 0,
      icon: record.icon ?? null,
      id: record.id,
      label: record.label,
      open: children.length > 0,
      openUrl: typeof record.openUrl === "string" ? record.openUrl : null,
      panelUrl: typeof record.panelUrl === "string" ? record.panelUrl : null,
      path: record.pathLabel ?? record.id,
      pathParts: match?.pathParts ?? null,
      status: record.status ?? null,
      title: record.displayTitle ?? record.label,
      titleParts: match?.titleParts ?? null,
      uuid: record.uuid ?? null,
      value: record.value ?? record.id,
    });
  }

  items.sort((a, b) => {
    if (a._rank !== b._rank) {
      return a._rank - b._rank;
    }

    return a._order - b._order;
  });

  return items.map(({ _order, _rank, ...item }) => item);
}

function compareSearchResults(a, b, query, options) {
  const aTier = searchPrefixTier(a.obj, query, options);
  const bTier = searchPrefixTier(b.obj, query, options);

  if (aTier !== bTier) {
    return bTier - aTier;
  }

  const aStartRatio = searchResultStartRatio(a, options);
  const bStartRatio = searchResultStartRatio(b, options);

  if (aStartRatio !== bStartRatio) {
    return aStartRatio - bStartRatio;
  }

  const aStartIndex = searchResultStartIndex(a, options);
  const bStartIndex = searchResultStartIndex(b, options);

  if (aStartIndex !== bStartIndex) {
    return aStartIndex - bStartIndex;
  }

  return b.score - a.score;
}

function findDirectMatches(query, options) {
  if (query === "") {
    return [];
  }

  if (options.pathOnly === true) {
    return searchRecords.filter((record) => (
      record.normalizedPath.includes(query) === true
    ));
  }

  return searchRecords.filter((record) => (
    record.normalizedFlag.includes(query) === true ||
    record.normalizedTitle.includes(query) === true ||
    record.normalizedPath.includes(query) === true
  ));
}

function highlightParts(text, indexes = []) {
  if (typeof text !== "string" || text === "") {
    return [];
  }

  if (Array.isArray(indexes) !== true || indexes.length === 0) {
    return [{ text, match: false }];
  }

  const matchedIndexes = new Set(indexes);
  const parts = [];
  let currentText = "";
  let currentMatch = matchedIndexes.has(0);

  for (let index = 0; index < text.length; index++) {
    const character = text[index];
    const isMatch = matchedIndexes.has(index);

    if (index === 0) {
      currentMatch = isMatch;
    } else if (isMatch !== currentMatch) {
      parts.push({
        text: currentText,
        match: currentMatch,
      });
      currentText = "";
      currentMatch = isMatch;
    }

    currentText += character;
  }

  if (currentText !== "") {
    parts.push({
      text: currentText,
      match: currentMatch,
    });
  }

  return parts;
}

function normalizeSearchText(value) {
  return normalizeSearchValue(value)
    .toLowerCase()
    .replace(/[-_/]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function normalizeSearchValue(value) {
  if (typeof value === "string") {
    return value;
  }

  if (value === null || value === undefined) {
    return "";
  }

  if (typeof value === "object") {
    if (typeof value.value === "string") {
      return value.value;
    }

    if (typeof value.query === "string") {
      return value.query;
    }

    if (typeof value.target?.value === "string") {
      return value.target.value;
    }

    if (typeof value.detail?.value === "string") {
      return value.detail.value;
    }
  }

  return String(value);
}

function originalIndexes(indexes = [], indexMap = []) {
  if (Array.isArray(indexes) !== true || indexes.length === 0) {
    return [];
  }

  const uniqueIndexes = new Set();

  for (const index of indexes) {
    const originalIndex = indexMap[index];

    if (typeof originalIndex === "number") {
      uniqueIndexes.add(originalIndex);
    }
  }

  return Array.from(uniqueIndexes).sort((a, b) => a - b);
}

function parseFeatureFlagLabel(value) {
  if (typeof value !== "string" || value.startsWith(FEATURE_FLAG_MARKER) !== true) {
    return null;
  }

  const match = value
    .slice(FEATURE_FLAG_MARKER.length)
    .match(/^([a-z0-9][a-z0-9-]*):\s+(.+)$/s);

  if (!match) {
    return null;
  }

  return {
    flag: match[1],
    title: match[2],
  };
}

function pathPartsForResult(record, pathResult) {
  if (Array.isArray(pathResult?.indexes) !== true || pathResult.indexes.length === 0) {
    return [];
  }

  if (record.normalizedPath === record.normalizedTitle) {
    return [];
  }

  return highlightParts(
    record.pathLabel,
    originalIndexes(pathResult.indexes, record.pathIndexMap)
  );
}

function prepareSearchTarget(value) {
  const raw = normalizeSearchValue(value);
  const normalizedChars = [];
  const indexMap = [];
  let pendingSpaceIndex = null;

  for (let index = 0; index < raw.length; index++) {
    const character = raw[index];
    const normalizedCharacter = character.toLowerCase();

    if (/[-_/]/.test(normalizedCharacter) === true || /\s/.test(normalizedCharacter) === true) {
      if (normalizedChars.length > 0 && pendingSpaceIndex === null) {
        pendingSpaceIndex = index;
      }

      continue;
    }

    if (pendingSpaceIndex !== null) {
      normalizedChars.push(" ");
      indexMap.push(pendingSpaceIndex);
      pendingSpaceIndex = null;
    }

    normalizedChars.push(normalizedCharacter);
    indexMap.push(index);
  }

  return {
    indexMap,
    text: normalizedChars.join(""),
  };
}

function resultFlag(result, options) {
  if (options.pathOnly === true) {
    return null;
  }

  return result[2] ?? null;
}

function resultPath(result, options) {
  if (options.pathOnly === true) {
    return result;
  }

  return result[1] ?? null;
}

function resultTitle(result, options) {
  if (options.pathOnly === true) {
    return null;
  }

  return result[0] ?? null;
}

function searchOptions(rawQuery) {
  return {
    pathOnly: rawQuery.includes("/") === true,
  };
}

function searchPrefixTier(record, query, options) {
  if (query === "") {
    return 0;
  }

  const pathExact = record.normalizedPath === query;
  const pathStarts = record.normalizedPath.startsWith(query) === true;
  const pathSegmentStarts = record.normalizedPath.includes(` ${query}`) === true;
  const titleExact = options.pathOnly !== true && record.normalizedTitle === query;
  const titleStarts = options.pathOnly !== true && record.normalizedTitle.startsWith(query) === true;
  const flagStarts = options.pathOnly !== true && record.normalizedFlag.startsWith(query) === true;

  if (pathExact === true) {
    return 6;
  }

  if (pathStarts === true) {
    return 5;
  }

  if (titleExact === true) {
    return 4;
  }

  if (titleStarts === true) {
    return 3;
  }

  if (pathSegmentStarts === true) {
    return 2;
  }

  if (flagStarts === true) {
    return 1;
  }

  return 0;
}

function searchResults(query, options) {
  const directMatches = findDirectMatches(query, options);
  const records = directMatches.length > 0 ? directMatches : searchRecords;

  if (options.pathOnly === true) {
    return fuzzysort.go(query, records, {
      key: "pathPrepared",
    }).sort((a, b) => compareSearchResults(a, b, query, options));
  }

  return fuzzysort.go(query, records, {
    keys: ["titlePrepared", "pathPrepared", "flagPrepared"],
  }).sort((a, b) => compareSearchResults(a, b, query, options));
}

function searchResultStartIndex(result, options) {
  const indexes = [];
  const titleResult = resultTitle(result, options);
  const pathResult = resultPath(result, options);
  const flagResult = resultFlag(result, options);

  if (typeof titleResult?.indexes?.[0] === "number") {
    indexes.push(titleResult.indexes[0]);
  }

  if (typeof pathResult?.indexes?.[0] === "number") {
    indexes.push(pathResult.indexes[0]);
  }

  if (typeof flagResult?.indexes?.[0] === "number") {
    indexes.push(flagResult.indexes[0]);
  }

  if (indexes.length === 0) {
    return Number.POSITIVE_INFINITY;
  }

  return Math.min(...indexes);
}

function searchResultStartRatio(result, options) {
  const ratios = [];
  const titleResult = resultTitle(result, options);
  const pathResult = resultPath(result, options);
  const flagResult = resultFlag(result, options);

  if (typeof titleResult?.indexes?.[0] === "number" && result.obj.normalizedTitle.length > 0) {
    ratios.push(titleResult.indexes[0] / result.obj.normalizedTitle.length);
  }

  if (typeof pathResult?.indexes?.[0] === "number" && result.obj.normalizedPath.length > 0) {
    ratios.push(pathResult.indexes[0] / result.obj.normalizedPath.length);
  }

  if (typeof flagResult?.indexes?.[0] === "number" && result.obj.normalizedFlag.length > 0) {
    ratios.push(flagResult.indexes[0] / result.obj.normalizedFlag.length);
  }

  if (ratios.length === 0) {
    return Number.POSITIVE_INFINITY;
  }

  return Math.min(...ratios);
}

function setSearchIndex(records) {
  searchChildrenByParentId = Object.create(null);
  searchRecords = [];
  searchRecordsById = Object.create(null);

  searchChildrenByParentId[SEARCH_ROOT_ID] = [];

  records.forEach((record) => {
    const parentId = record.parentId ?? SEARCH_ROOT_ID;
    const parsedLabel = parseFeatureFlagLabel(record.label);
    const displayTitle = parsedLabel?.title ?? record.label ?? "";
    const featureFlag = parsedLabel?.flag ?? null;
    const normalizedFlag = prepareSearchTarget(featureFlag);
    const pathLabel = typeof record.path === "string" && record.path !== ""
      ? record.path
      : record.id;
    const normalizedPath = prepareSearchTarget(pathLabel);
    const normalizedTitle = prepareSearchTarget(displayTitle);
    const searchRecord = {
      ...record,
      displayTitle,
      featureFlag,
      flagIndexMap: normalizedFlag.indexMap,
      flagPrepared: fuzzysort.prepare(normalizedFlag.text),
      normalizedPath: normalizedPath.text,
      normalizedFlag: normalizedFlag.text,
      normalizedTitle: normalizedTitle.text,
      order: searchRecords.length,
      pathIndexMap: normalizedPath.indexMap,
      pathLabel,
      pathPrepared: fuzzysort.prepare(normalizedPath.text),
      status: typeof record.status === "string" ? record.status : null,
      titleIndexMap: normalizedTitle.indexMap,
      titlePrepared: fuzzysort.prepare(normalizedTitle.text),
    };

    searchChildrenByParentId[parentId] ??= [];
    searchChildrenByParentId[parentId].push(record.id);
    searchRecords.push(searchRecord);
    searchRecordsById[record.id] = searchRecord;
  });
}

function updateSearchResults(rawQuery) {
  const query = normalizeSearchText(rawQuery);
  const options = searchOptions(rawQuery);

  if (query === "") {
    return [];
  }

  const keptIds = new Set();
  const matchesById = Object.create(null);
  const subtreeRanks = Object.create(null);
  const results = searchResults(query, options);

  let resultIndex = 0;
  for (const result of results) {
    const record = result.obj;
    const titleResult = resultTitle(result, options);
    const pathResult = resultPath(result, options);
    const flagResult = resultFlag(result, options);

    matchesById[record.id] = {
      flagParts: record.featureFlag
        ? highlightParts(
          record.featureFlag,
          originalIndexes(flagResult?.indexes, record.flagIndexMap)
        )
        : null,
      pathParts: pathPartsForResult(record, pathResult),
      titleParts: highlightParts(
        record.displayTitle,
        originalIndexes(titleResult?.indexes, record.titleIndexMap)
      ),
    };

    let currentId = record.id;
    while (currentId) {
      keptIds.add(currentId);
      subtreeRanks[currentId] = Math.min(
        subtreeRanks[currentId] ?? Number.POSITIVE_INFINITY,
        resultIndex
      );
      currentId = searchRecordsById[currentId]?.parentId ?? null;
    }

    resultIndex += 1;
  }

  return buildSearchTree(SEARCH_ROOT_ID, keptIds, matchesById, subtreeRanks);
}

self.addEventListener("message", (event) => {
  const message = event.data ?? {};
  const type = message.type;
  const requestId = message.requestId;

  if (typeof requestId !== "number") {
    return;
  }

  switch (type) {
  case "set-search-index":
    setSearchIndex(Array.isArray(message.records) === true ? message.records : []);
    self.postMessage({
      requestId,
      type: "search-index-ready",
    });
    break;
  case "run-search":
    self.postMessage({
      items: updateSearchResults(normalizeSearchValue(message.query)),
      requestId,
      type: "search-results",
    });
    break;
  default:
    break;
  }
});
