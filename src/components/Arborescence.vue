<template>
  <section class="k-arborescence-section">
    <div class="k-arborescence-search">
      <k-input
        ref="searchInput"
        :autofocus="autofocus"
        :value="searchQuery"
        autocomplete="off"
        placeholder="Search title or path"
        spellcheck="false"
        type="text"
        @input="onSearchInput"
      >
        <template slot="before">
          <k-icon type="search" />
        </template>
      </k-input>
    </div>

    <ul
      v-if="showTree"
      :class="['k-tree', $options.name, $attrs.class]"
      :style="{ '--tree-level': 0, ...$attrs.style }"
    >
      <li
        key="parent-page"
        :aria-expanded="true"
        :aria-current="false"
      >
        <p v-if="showParentBranch" class="k-tree-branch">
          <button
            class="k-tree-toggle"
            :disabled="false"
            type="button"
          >
            <k-icon type="angle-down" />
          </button>
          <component
            :is="parentOpenTarget ? 'a' : 'span'"
            :aria-disabled="parentOpenTarget ? null : 'true'"
            :href="parentOpenTarget ? parentLinkHref : null"
            class="k-tree-folder"
            @click="openParent"
          >
            <k-icon-frame :icon="parentIcon" />
            <span class="k-tree-folder-label">{{ parentTitle }}</span>
          </component>
        </p>
        <page-tree-menu
          v-if="resolvedRoot"
          :key="treeKey"
          :branch-sorts="configuredBranchSorts"
          :items="displayedTreeItems"
          :level="treeLevel"
          :parent="resolvedRoot"
          :show-paths="resolvedShowPaths"
          @select="onSelect"
        />
      </li>
    </ul>

    <k-empty
      v-if="isSearchActive && displayedTreeItems !== null && displayedTreeItems.length === 0"
      icon="search"
      text="No matching pages"
    />
  </section>
</template>

<script>
import fuzzysort from "fuzzysort";

const SEARCH_ROOT_ID = "__arborescence_search_root__";
const FEATURE_FLAG_MARKER = "\u2063";

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

export default {
  props: {
    autofocus: {
      type: Boolean,
      default: false,
    },
    closeOnSelect: {
      type: Boolean,
      default: false,
    },
    standaloneRootPage: {
      type: String,
      default: null,
    },
    standaloneShowParent: {
      type: Boolean,
      default: true,
    },
    standaloneShowPaths: {
      type: Boolean,
      default: true,
    },
    standaloneBranchSorts: {
      type: Object,
      default: null,
    },
  },
  data() {
    return {
      branchSorts: null,
      headline: null,
      hasLoadedSearchIndex: false,
      isSite: false,
      label: null,
      defaultTreeItems: null,
      parentIcon: null,
      parentOpenTarget: null,
      parentTitle: null,
      root: "",
      searchChildrenByParentId: Object.create(null),
      searchQuery: "",
      searchRecords: [],
      searchRecordsById: Object.create(null),
      searchTreeItems: null,
      searchTreeVersion: 0,
      showParent: true,
      showPaths: true,
    };
  },

  computed: {
    configuredBranchSorts() {
      return this.normalizeBranchSorts(
        this.branchSorts ?? this.standaloneBranchSorts ?? {}
      );
    },
    displayedTreeItems() {
      if (this.isSearchActive === true) {
        return this.searchTreeItems;
      }

      return this.defaultTreeItems;
    },
    isSearchActive() {
      return this.trimmedSearchQuery !== "";
    },
    hasVisibleSearchResults() {
      return this.isSearchActive === true &&
        Array.isArray(this.displayedTreeItems) === true &&
        this.displayedTreeItems.length > 0;
    },
    trimmedSearchQuery() {
      return this.normalizeSearchValue(this.searchQuery).trim();
    },
    showTree() {
      if (this.displayedTreeItems === null) {
        return false;
      }

      if (this.isSearchActive === true) {
        return this.displayedTreeItems.length > 0;
      }

      return true;
    },
    resolvedRoot() {
      if (typeof this.root === "string" && this.root !== "") {
        return this.root;
      }

      if (this.isSite === true) {
        return "site";
      }

      if (typeof this.parent === "string" && this.parent !== "") {
        return this.parent;
      }

      return "site";
    },
    showParentBranch() {
      return this.showParent === true &&
        (this.isSearchActive !== true || this.hasVisibleSearchResults === true);
    },
    parentLinkHref() {
      if (!this.parentOpenTarget) {
        return null;
      }

      return this.$url(`/${this.parentOpenTarget}`);
    },
    resolvedShowPaths() {
      return this.showPaths !== false;
    },
    treeLevel() {
      return this.showParentBranch === true ? 1 : 0;
    },
    treeKey() {
      if (this.isSearchActive === true) {
        return `search-${this.searchTreeVersion}`;
      }

      return "browse";
    },
    serializedBranchSorts() {
      return JSON.stringify(this.configuredBranchSorts);
    },
  },

  created: async function() {
    const response = await this.loadInitialData();
    this.applyResponse(response);
    this.$nextTick(() => {
      this.loadSearchIndex();
    });
  },
  mounted() {
    if (this.autofocus === true) {
      this.$nextTick(() => {
        this.focusSearch();
      });
    }
  },

  methods: {
    applyResponse(response = {}) {
      this.branchSorts = response.branchSorts ?? this.standaloneBranchSorts ?? {};
      this.headline = response.headline;
      this.root = response.rootPage ?? this.parent;
      this.parentIcon = response.parentIcon ?? "folder";
      this.showParent = response.showParent ?? true;
      this.showPaths = response.showPaths ?? this.standaloneShowPaths ?? true;
      this.label = response.label;
      this.parentTitle = response.parentTitle;
      this.parentOpenTarget = response.parentOpenTarget ?? null;
      this.isSite = response.isSite;
      this.defaultTreeItems = response.pages ?? [];
    },
    buildSearchTree(parentId, keptIds, matchesById, subtreeRanks) {
      const items = [];

      for (const childId of this.searchChildrenByParentId[parentId] ?? []) {
        if (keptIds.has(childId) !== true) {
          continue;
        }

        const record = this.searchRecordsById[childId];
        if (!record) {
          continue;
        }

        const children = this.buildSearchTree(record.id, keptIds, matchesById, subtreeRanks);
        const match = matchesById[record.id] ?? null;
        items.push({
          _order: record.order ?? 0,
          _rank: subtreeRanks[record.id] ?? Number.POSITIVE_INFINITY,
          children,
          disabled: false,
          featureFlag: record.featureFlag ?? null,
          flagParts: match?.flagParts ?? null,
          hasChildren: children.length > 0,
          icon: record.icon ?? null,
          id: record.id,
          label: record.label,
          open: children.length > 0,
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
    },
    clearSearchResults() {
      this.searchTreeItems = [];
      this.searchTreeVersion += 1;
    },
    compareSearchResults(a, b, query, options) {
      const aTier = this.searchPrefixTier(a.obj, query, options);
      const bTier = this.searchPrefixTier(b.obj, query, options);

      if (aTier !== bTier) {
        return bTier - aTier;
      }

      const aStartRatio = this.searchResultStartRatio(a, options);
      const bStartRatio = this.searchResultStartRatio(b, options);

      if (aStartRatio !== bStartRatio) {
        return aStartRatio - bStartRatio;
      }

      const aStartIndex = this.searchResultStartIndex(a, options);
      const bStartIndex = this.searchResultStartIndex(b, options);

      if (aStartIndex !== bStartIndex) {
        return aStartIndex - bStartIndex;
      }

      return b.score - a.score;
    },
    findDirectMatches(query, options) {
      if (query === "") {
        return [];
      }

      if (options.pathOnly === true) {
        return this.searchRecords.filter((record) => (
          record.normalizedPath.includes(query) === true
        ));
      }

      return this.searchRecords.filter((record) => (
        record.normalizedFlag.includes(query) === true ||
        record.normalizedTitle.includes(query) === true ||
        record.normalizedPath.includes(query) === true
      ));
    },
    highlightParts(text, indexes = []) {
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
    },
    async loadSearchIndex() {
      try {
        const response = await this.$api.get("arborescence/search-index", {
          branchSorts: this.serializedBranchSorts,
          root: this.resolvedRoot,
        }, null, true);

        this.setSearchIndex(response?.searchIndex ?? []);
      } catch (error) {
        window.panel.error(error, false);
      } finally {
        this.hasLoadedSearchIndex = true;

        if (this.isSearchActive === true) {
          this.updateSearchResults();
        }
      }
    },
    normalizeSearchText(value) {
      return this.normalizeSearchValue(value)
        .toLowerCase()
        .replace(/[-_/]+/g, " ")
        .replace(/\s+/g, " ")
        .trim();
    },
    normalizeSearchValue(value) {
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
    },
    normalizeBranchSorts(value) {
      if (!value || typeof value !== "object" || Array.isArray(value) === true) {
        return {};
      }

      return Object.entries(value).reduce((branchSorts, [branch, sortBy]) => {
        if (typeof branch !== "string" || typeof sortBy !== "string") {
          return branchSorts;
        }

        const normalizedBranch = branch
          .trim()
          .replace(/^pages\//, "")
          .replace(/^\/+|\/+$/g, "")
          .replaceAll("+", "/");
        const normalizedSortBy = sortBy.trim();

        if (normalizedBranch === "" || normalizedBranch === "site" || normalizedSortBy === "") {
          return branchSorts;
        }

        branchSorts[normalizedBranch] = normalizedSortBy;
        return branchSorts;
      }, {});
    },
    focusSearch({ select = true } = {}) {
      const input = this.$refs.searchInput?.$el?.querySelector("input");

      if (!input) {
        return false;
      }

      input.focus();

      if (select === true && typeof input.select === "function") {
        input.select();
      }

      return true;
    },
    isPlainLeftClick(event) {
      if (!event) {
        return true;
      }

      if (event.metaKey || event.altKey || event.ctrlKey || event.shiftKey) {
        return false;
      }

      if (event.defaultPrevented === true) {
        return false;
      }

      if (event.button !== undefined && event.button !== 0) {
        return false;
      }

      return true;
    },
    async loadInitialData() {
      if (typeof this.standaloneRootPage === "string" && this.standaloneRootPage !== "") {
        return this.$api.get("arborescence/tree", {
          branchSorts: this.serializedBranchSorts,
          root: this.standaloneRootPage,
          showParent: this.standaloneShowParent ? "1" : "0",
          showPaths: this.standaloneShowPaths ? "1" : "0",
        }, null, true);
      }

      return this.load();
    },
    openParent(event = null) {
      if (!this.parentOpenTarget) {
        return;
      }

      if (event && this.isPlainLeftClick(event) !== true) {
        return;
      }

      event?.preventDefault();
      window.panel.open(this.parentOpenTarget);

      if (this.closeOnSelect === true) {
        this.$panel.dialog.close();
      }
    },
    onSearchInput(value) {
      this.searchQuery = this.normalizeSearchValue(value);
      this.updateSearchResults();
    },
    onSelect(name) {
      let uid = null;

      if (typeof name.children === "string") {
        uid = name.children;
      } else {
        uid = `pages/${name.id.replaceAll("/", "+")}`;
      }

      if (this.parent == uid) {
        return;
      }

      window.panel.open(uid);

      if (this.closeOnSelect === true) {
        this.$panel.dialog.close();
      }
    },
    originalIndexes(indexes = [], indexMap = []) {
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
    },
    pathPartsForResult(record, pathResult) {
      if (Array.isArray(pathResult?.indexes) !== true || pathResult.indexes.length === 0) {
        return [];
      }

      if (record.normalizedPath === record.normalizedTitle) {
        return [];
      }

      return this.highlightParts(
        record.pathLabel,
        this.originalIndexes(pathResult.indexes, record.pathIndexMap)
      );
    },
    prepareSearchTarget(value) {
      const raw = this.normalizeSearchValue(value);
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
    },
    resultFlag(result, options) {
      if (options.pathOnly === true) {
        return null;
      }

      return result[2] ?? null;
    },
    resultPath(result, options) {
      if (options.pathOnly === true) {
        return result;
      }

      return result[1] ?? null;
    },
    resultTitle(result, options) {
      if (options.pathOnly === true) {
        return null;
      }

      return result[0] ?? null;
    },
    searchOptions(rawQuery) {
      return {
        pathOnly: rawQuery.includes("/") === true,
      };
    },
    searchPrefixTier(record, query, options) {
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
    },
    searchResults(query, options) {
      const directMatches = this.findDirectMatches(query, options);
      const records = directMatches.length > 0 ? directMatches : this.searchRecords;

      if (options.pathOnly === true) {
        return fuzzysort.go(query, records, {
          key: "pathPrepared",
        }).sort((a, b) => this.compareSearchResults(a, b, query, options));
      }

      return fuzzysort.go(query, records, {
        keys: ["titlePrepared", "pathPrepared", "flagPrepared"],
      }).sort((a, b) => this.compareSearchResults(a, b, query, options));
    },
    searchResultStartIndex(result, options) {
      const indexes = [];
      const titleResult = this.resultTitle(result, options);
      const pathResult = this.resultPath(result, options);
      const flagResult = this.resultFlag(result, options);

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
    },
    searchResultStartRatio(result, options) {
      const ratios = [];
      const titleResult = this.resultTitle(result, options);
      const pathResult = this.resultPath(result, options);
      const flagResult = this.resultFlag(result, options);

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
    },
    setSearchIndex(records) {
      const searchChildrenByParentId = Object.create(null);
      const searchRecords = [];
      const searchRecordsById = Object.create(null);

      searchChildrenByParentId[SEARCH_ROOT_ID] = [];

      records.forEach((record) => {
        const parentId = record.parentId ?? SEARCH_ROOT_ID;
        const parsedLabel = parseFeatureFlagLabel(record.label);
        const displayTitle = parsedLabel?.title ?? record.label ?? "";
        const featureFlag = parsedLabel?.flag ?? null;
        const normalizedFlag = this.prepareSearchTarget(featureFlag);
        const pathLabel = typeof record.path === "string" && record.path !== ""
          ? record.path
          : record.id;
        const normalizedPath = this.prepareSearchTarget(pathLabel);
        const normalizedTitle = this.prepareSearchTarget(displayTitle);
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

      this.searchChildrenByParentId = searchChildrenByParentId;
      this.searchRecords = searchRecords;
      this.searchRecordsById = searchRecordsById;
    },
    updateSearchResults() {
      const rawQuery = this.trimmedSearchQuery;
      const query = this.normalizeSearchText(rawQuery);
      const options = this.searchOptions(rawQuery);

      if (query === "") {
        this.clearSearchResults();
        return;
      }

      if (this.defaultTreeItems === null || this.hasLoadedSearchIndex !== true) {
        this.searchTreeItems = null;
        this.searchTreeVersion += 1;
        return;
      }

      const keptIds = new Set();
      const matchesById = Object.create(null);
      const subtreeRanks = Object.create(null);
      const results = this.searchResults(query, options);

      let resultIndex = 0;
      for (const result of results) {
        const record = result.obj;
        const titleResult = this.resultTitle(result, options);
        const pathResult = this.resultPath(result, options);
        const flagResult = this.resultFlag(result, options);

        matchesById[record.id] = {
          flagParts: record.featureFlag
            ? this.highlightParts(
              record.featureFlag,
              this.originalIndexes(flagResult?.indexes, record.flagIndexMap)
            )
            : null,
          pathParts: this.pathPartsForResult(record, pathResult),
          titleParts: this.highlightParts(
            record.displayTitle,
            this.originalIndexes(titleResult?.indexes, record.titleIndexMap)
          ),
        };

        let currentId = record.id;
        while (currentId) {
          keptIds.add(currentId);
          subtreeRanks[currentId] = Math.min(
            subtreeRanks[currentId] ?? Number.POSITIVE_INFINITY,
            resultIndex
          );
          currentId = this.searchRecordsById[currentId]?.parentId ?? null;
        }

        resultIndex += 1;
      }

      this.searchTreeItems = this.buildSearchTree(SEARCH_ROOT_ID, keptIds, matchesById, subtreeRanks);
      this.searchTreeVersion += 1;
    },
  },
};
</script>

<style>
.k-arborescence-section .k-tree-folder {
  color: inherit;
  text-decoration: none;
}

.k-arborescence-search {
  margin-bottom: var(--spacing-4);
}
</style>
