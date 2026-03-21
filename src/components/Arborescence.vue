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
          :expanded-lookup="browseExpandedLookup"
          :items="displayedTreeItems"
          :level="treeLevel"
          :parent="resolvedRoot"
          :restore-expanded="isSearchActive !== true"
          :show-paths="resolvedShowPaths"
          @close="onBrowseClose"
          @open="onBrowseOpen"
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
import {
  loadCachedSearchIndex,
  storeCachedSearchIndex,
} from "../searchIndexStorage";
import SearchWorkerClient from "../searchWorkerClient";

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
      browseExpandedIds: [],
      parentIcon: null,
      parentOpenTarget: null,
      parentTitle: null,
      root: "",
      searchIndexRevision: null,
      searchIndexScope: null,
      searchQuery: "",
      searchRequestToken: 0,
      searchTreeItems: null,
      searchTreeVersion: 0,
      searchWorker: null,
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
    browseExpandedLookup() {
      return this.browseExpandedIds.reduce((lookup, id) => {
        lookup[id] = true;
        return lookup;
      }, Object.create(null));
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
    browseExpansionStorageKey() {
      return this.browseExpansionStorageKeyForRoot(this.resolvedRoot);
    },
    searchQueryStorageKey() {
      return this.searchQueryStorageKeyForRoot(this.resolvedRoot);
    },
    serializedBranchSorts() {
      return JSON.stringify(this.configuredBranchSorts);
    },
  },

  created: async function() {
    this.initializeSearchWorker();
    const response = await this.loadInitialData();
    await this.applyResponse(response);
  },
  beforeDestroy() {
    this.searchWorker?.terminate();
  },
  mounted() {
    if (this.autofocus === true) {
      this.$nextTick(() => {
        this.focusSearch();
      });
    }
  },

  methods: {
    async applyResponse(response = {}) {
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
      this.searchIndexRevision = response.searchIndexRevision ?? null;
      this.searchIndexScope = response.searchIndexScope ?? null;
      this.restoreBrowseExpansionState();
      const restoredSearchQuery = this.restoreSearchQueryState();
      this.restoreSearchIndexState(response);

      this.defaultTreeItems = await this.prepareInitialBrowseTreeItems(response.pages ?? []);

      if (restoredSearchQuery === true) {
        this.maybeUpdateSearchResults();

        if (this.autofocus === true) {
          this.$nextTick(() => {
            this.focusSearch();
          });
        }
      }
    },
    panelUserId() {
      return this.$panel?.user?.id ??
        document.querySelector(".k-panel")?.dataset.user ??
        "guest";
    },
    browseExpansionStorageKeyForRoot(root = this.resolvedRoot) {
      return `kirby$arborescence$expanded$${this.panelUserId()}$${root}`;
    },
    clearSearchResults() {
      this.searchRequestToken += 1;
      this.searchTreeItems = [];
      this.searchTreeVersion += 1;
    },
    async syncSearchIndex(records) {
      if (!this.searchWorker) {
        return false;
      }

      try {
        await this.searchWorker.setSearchIndex(records ?? []);
      } catch (error) {
        window.panel.error(error, false);
        return false;
      }

      this.hasLoadedSearchIndex = true;
      return true;
    },
    initializeSearchWorker() {
      if (this.searchWorker) {
        return;
      }

      try {
        this.searchWorker = new SearchWorkerClient();
      } catch (error) {
        window.panel.error(error, false);
      }
    },
    async loadSearchIndex() {
      try {
        const response = await this.$api.get("arborescence/search-index", {
          branchSorts: this.serializedBranchSorts,
          root: this.resolvedRoot,
        }, null, true);

        const searchIndex = response?.searchIndex ?? [];
        this.searchIndexRevision = response?.searchIndexRevision ?? this.searchIndexRevision;
        this.searchIndexScope = response?.searchIndexScope ?? this.searchIndexScope;
        const hasLoadedSearchIndex = await this.syncSearchIndex(searchIndex);
        this.persistCachedSearchIndex(searchIndex).catch(() => {});

        if (hasLoadedSearchIndex === true) {
          this.maybeUpdateSearchResults();
        }
      } catch (error) {
        window.panel.error(error, false);
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
    persistBrowseExpansionState() {
      if (typeof localStorage === "undefined") {
        return;
      }

      try {
        if (this.browseExpandedIds.length === 0) {
          localStorage.removeItem(this.browseExpansionStorageKey);
          return;
        }

        localStorage.setItem(
          this.browseExpansionStorageKey,
          JSON.stringify(this.browseExpandedIds)
        );
      } catch (error) {
        return;
      }
    },
    restoreBrowseExpansionState() {
      if (typeof localStorage === "undefined") {
        this.browseExpandedIds = [];
        return;
      }

      try {
        const value = localStorage.getItem(this.browseExpansionStorageKey);
        const storedIds = value ? JSON.parse(value) : [];

        this.browseExpandedIds = Array.isArray(storedIds) === true
          ? [...new Set(storedIds.filter((id) => typeof id === "string" && id !== ""))]
          : [];
      } catch (error) {
        this.browseExpandedIds = [];
      }
    },
    persistSearchQueryState() {
      if (typeof localStorage === "undefined") {
        return;
      }

      const query = this.normalizeSearchValue(this.searchQuery);

      try {
        if (query.trim() === "") {
          localStorage.removeItem(this.searchQueryStorageKey);
          return;
        }

        localStorage.setItem(this.searchQueryStorageKey, query);
      } catch (error) {
        return;
      }
    },
    async persistCachedSearchIndex(records) {
      await storeCachedSearchIndex({
        records,
        revision: this.searchIndexRevision,
        scope: this.searchIndexScope,
      });
    },
    restoreSearchQueryState() {
      if (typeof localStorage === "undefined") {
        this.searchQuery = "";
        return false;
      }

      try {
        const value = localStorage.getItem(this.searchQueryStorageKey);
        this.searchQuery = typeof value === "string"
          ? this.normalizeSearchValue(value)
          : "";
      } catch (error) {
        this.searchQuery = "";
      }

      return this.trimmedSearchQuery !== "";
    },
    async restoreSearchIndexState(response = {}) {
      if (Array.isArray(response.searchIndex) === true) {
        const hasLoadedSearchIndex = await this.syncSearchIndex(response.searchIndex);
        this.persistCachedSearchIndex(response.searchIndex).catch(() => {});

        if (hasLoadedSearchIndex === true) {
          this.maybeUpdateSearchResults();
        }

        return;
      }

      const cachedSearchIndex = await this.restoreCachedSearchIndex();

      if (Array.isArray(cachedSearchIndex?.records) === true) {
        const hasLoadedSearchIndex = await this.syncSearchIndex(cachedSearchIndex.records);

        if (hasLoadedSearchIndex === true) {
          this.maybeUpdateSearchResults();
        }

        if (
          this.searchIndexRevision !== null &&
          cachedSearchIndex.revision === this.searchIndexRevision
        ) {
          return;
        }
      }

      this.loadSearchIndex();
    },
    async restoreCachedSearchIndex() {
      return loadCachedSearchIndex(this.searchIndexScope);
    },
    async prepareInitialBrowseTreeItems(items) {
      if (Array.isArray(items) !== true || items.length === 0) {
        return [];
      }

      if (this.browseExpandedIds.length === 0) {
        return items;
      }

      return this.preloadExpandedBrowseItems(items);
    },
    async preloadExpandedBrowseItems(items) {
      const preparedItems = [];

      for (const item of items) {
        preparedItems.push(await this.preloadExpandedBrowseItem(item));
      }

      return preparedItems;
    },
    async preloadExpandedBrowseItem(item) {
      if (!item || typeof item !== "object") {
        return item;
      }

      const preparedItem = {
        ...item,
      };

      if (Array.isArray(preparedItem.children) === true) {
        preparedItem.children = await this.preloadExpandedBrowseItems(preparedItem.children);
        preparedItem.open = preparedItem.children.length > 0 && preparedItem.open === true;
        return preparedItem;
      }

      if (
        preparedItem.hasChildren !== true ||
        typeof preparedItem.id !== "string" ||
        preparedItem.id === "" ||
        this.browseExpandedLookup[preparedItem.id] !== true ||
        typeof preparedItem.children !== "string"
      ) {
        return preparedItem;
      }

      try {
        const children = await this.$api.get("arborescence/children", {
          branchSorts: this.serializedBranchSorts,
          parent: preparedItem.children,
        }, null, true);

        preparedItem.children = await this.preloadExpandedBrowseItems(children ?? []);
        preparedItem.open = true;
      } catch (error) {
        return preparedItem;
      }

      return preparedItem;
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
    onBrowseClose(item) {
      if (this.isSearchActive === true) {
        return;
      }

      this.updateBrowseExpansionState(item, false);
    },
    onBrowseOpen(item) {
      if (this.isSearchActive === true) {
        return;
      }

      this.updateBrowseExpansionState(item, true);
    },
    updateBrowseExpansionState(item, isOpen) {
      if (!item || typeof item.id !== "string" || item.id === "") {
        return;
      }

      const descendantsPrefix = `${item.id}/`;
      const nextExpandedIds = isOpen === true
        ? [...new Set([...this.browseExpandedIds, item.id])]
        : this.browseExpandedIds.filter((id) => (
          id !== item.id &&
          id.startsWith(descendantsPrefix) !== true
        ));

      this.browseExpandedIds = nextExpandedIds;
      this.persistBrowseExpansionState();
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
    searchQueryStorageKeyForRoot(root = this.resolvedRoot) {
      return `kirby$arborescence$query$${this.panelUserId()}$${root}`;
    },
    onSearchInput(value) {
      this.searchQuery = this.normalizeSearchValue(value);
      this.persistSearchQueryState();
      this.updateSearchResults();
    },
    maybeUpdateSearchResults() {
      if (this.isSearchActive !== true) {
        return;
      }

      if (this.defaultTreeItems === null || this.hasLoadedSearchIndex !== true) {
        return;
      }

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
    async updateSearchResults() {
      const rawQuery = this.trimmedSearchQuery;

      if (this.normalizeSearchText(rawQuery) === "") {
        this.clearSearchResults();
        return;
      }

      if (
        this.defaultTreeItems === null ||
        this.hasLoadedSearchIndex !== true ||
        this.searchWorker === null
      ) {
        this.searchRequestToken += 1;
        this.searchTreeItems = null;
        this.searchTreeVersion += 1;
        return;
      }

      const requestToken = this.searchRequestToken + 1;
      this.searchRequestToken = requestToken;

      let items = [];

      try {
        items = await this.searchWorker.search(rawQuery);
      } catch (error) {
        if (requestToken !== this.searchRequestToken) {
          return;
        }

        window.panel.error(error, false);
        return;
      }

      if (requestToken !== this.searchRequestToken) {
        return;
      }

      this.searchTreeItems = Array.isArray(items) === true ? items : [];
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
