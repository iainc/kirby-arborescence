<template>
  <ul
    :class="['k-tree', $options.name, $attrs.class]"
    :style="{ '--tree-level': level, ...$attrs.style }"
  >
    <li
      v-for="item in state"
      :key="item.value"
      :aria-expanded="item.open"
      :aria-current="isItem(item, current)"
    >
      <p class="k-tree-branch">
        <button
          :disabled="!item.hasChildren"
          class="k-tree-toggle"
          type="button"
          @click="toggle(item)"
        >
          <k-icon :type="arrow(item)" />
        </button>
        <component
          :is="item.disabled === true ? 'span' : 'a'"
          :aria-disabled="item.disabled === true ? 'true' : null"
          :href="item.disabled === true ? null : itemPanelHref(item)"
          class="k-tree-folder"
          @click="select(item, $event)"
        >
          <k-icon-frame :icon="itemIcon(item)" />
          <span
            :class="[
              'k-tree-folder-copy',
              { 'k-tree-folder-copy-with-path': pathParts(item).length > 0 }
            ]"
          >
            <span class="k-tree-folder-label">
              <span v-if="featureFlagParts(item).length > 0" class="k-ia-feature-flag-title">
                <span class="k-ia-feature-flag-title-label">
                  <template v-for="(part, index) in labelParts(item)">
                    <span
                      :key="`label-${item.value}-${index}`"
                      :class="{ 'k-tree-match': part.match }"
                      v-text="part.text"
                    ></span>
                  </template>
                </span>
                <span class="k-ia-feature-flag-pill">
                  <template v-for="(part, index) in featureFlagParts(item)">
                    <span
                      :key="`flag-${item.value}-${index}`"
                      :class="{ 'k-tree-match': part.match }"
                      v-text="part.text"
                    ></span>
                  </template>
                </span>
              </span>
              <template v-else>
                <template v-for="(part, index) in labelParts(item)">
                  <span
                    :key="`label-${item.value}-${index}`"
                    :class="{ 'k-tree-match': part.match }"
                    v-text="part.text"
                  ></span>
                </template>
              </template>
            </span>
            <span v-if="pathParts(item).length > 0" class="k-tree-folder-path">
              <template v-for="(part, index) in pathParts(item)">
                <span
                  :key="`path-${item.value}-${index}`"
                  :class="{ 'k-tree-match': part.match }"
                  v-text="part.text"
                ></span>
              </template>
            </span>
          </span>
          <span
            v-if="statusIcon(item)"
            class="k-tree-folder-status"
            :title="statusTitle(item)"
          >
            <k-icon :type="statusIcon(item)" />
          </span>
        </component>
        <k-options-dropdown
          v-if="hasItemOptions(item)"
          :options="itemOptions(item)"
          class="k-tree-branch-options"
          size="sm"
          @click.native.stop
        />
      </p>
      <template v-if="item.hasChildren && item.open">
        <component
          :is="$options.name"
          :ref="item.value"
          v-bind="$props"
          :items="item.children"
          :level="level + 1"
          @close="$emit('close', $event)"
          @open="$emit('open', $event)"
          @select="$emit('select', $event)"
          @toggle="$emit('toggle', $event)"
        />
      </template>
    </li>
  </ul>
</template>

<script>
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

function featureFlagTitlePrefix(flag) {
  if (typeof flag !== "string" || flag === "") {
    return null;
  }

  return `${flag}: `;
}

function featureFlagTitlePrefixLength(value, flag) {
  if (typeof value !== "string" || typeof flag !== "string" || flag === "") {
    return 0;
  }

  const parsedLabel = parseFeatureFlagLabel(value);

  if (parsedLabel?.flag === flag) {
    return FEATURE_FLAG_MARKER.length + featureFlagTitlePrefix(flag).length;
  }

  const prefix = featureFlagTitlePrefix(flag);

  if (prefix !== null && value.startsWith(prefix) === true) {
    return prefix.length;
  }

  return 0;
}

function stripFeatureFlagTitlePrefix(value, flag) {
  const prefixLength = featureFlagTitlePrefixLength(value, flag);

  if (prefixLength === 0) {
    return value;
  }

  return value.slice(prefixLength);
}

function stripFeatureFlagTitleParts(parts, flag) {
  if (Array.isArray(parts) !== true) {
    return parts;
  }

  const fullText = parts
    .map((part) => (typeof part?.text === "string" ? part.text : ""))
    .join("");

  const prefixLength = featureFlagTitlePrefixLength(fullText, flag);

  if (prefixLength === 0) {
    return parts;
  }

  let remainingPrefix = prefixLength;
  const trimmedParts = [];

  for (const part of parts) {
    const text = typeof part?.text === "string" ? part.text : "";

    if (text === "") {
      continue;
    }

    if (remainingPrefix >= text.length) {
      remainingPrefix -= text.length;
      continue;
    }

    const trimmedText = remainingPrefix > 0 ? text.slice(remainingPrefix) : text;
    remainingPrefix = 0;

    if (trimmedText === "") {
      continue;
    }

    trimmedParts.push({
      ...part,
      text: trimmedText,
    });
  }

  return trimmedParts;
}

export default {
  name: "page-tree-menu",
  inheritAttrs: false,
  props: {
    current: {
      type: String,
    },
    items: {
      type: [Array, Object],
    },
    branchSorts: {
      type: Object,
      default: () => ({}),
    },
    expandedLookup: {
      type: Object,
      default: () => ({}),
    },
    restoreExpanded: {
      type: Boolean,
      default: false,
    },
    showPaths: {
      type: Boolean,
      default: true,
    },
    level: {
      default: 0,
      type: Number,
    },
    move: {
      type: String,
    },
    root: {
      default: true,
      type: Boolean,
    },
  },
  emits: ["close", "open", "select", "toggle"],
  data() {
    return {
      didLoadError: false,
      state: this.items ?? [],
    };
  },
  watch: {
    items: {
      immediate: true,
      handler(items) {
        this.state = this.normalizeItems(items ?? []);
      },
    },
  },
  async mounted() {
    if (this.items) {
      this.state = this.normalizeItems(this.items);
      await this.restoreExpandedItems();
      return;
    }

    const items = await this.load(null);
    await this.open(items[0]);
    this.state = this.root ? items : items[0].children;

    if (this.current) {
      this.preselect(this.current);
    }
  },
  methods: {
    arrow(item) {
      if (item.loading === true) {
        return "loader";
      }

      return item.open ? "angle-down" : "angle-right";
    },
    close(item) {
      this.$set(item, "open", false);
      this.$emit("close", item);
    },
    findItem(id) {
      return this.state.find((item) => this.isItem(item, id));
    },
    isItem(item, target) {
      return item.value === target || item.uuid === target || item.id === target;
    },
    itemIcon(item) {
      return item.icon || "folder";
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
    itemPanelPath(item) {
      if (typeof item?.id !== "string" || item.id === "") {
        return null;
      }

      return `/pages/${item.id.replaceAll("/", "+")}`;
    },
    itemPanelHref(item) {
      const path = typeof item?.panelUrl === "string" && item.panelUrl !== ""
        ? item.panelUrl
        : this.itemPanelPath(item);

      if (path === null) {
        return null;
      }

      return this.$url(path);
    },
    itemPreviewHref(item) {
      const panelHref = this.itemPanelHref(item);

      if (panelHref === null) {
        return null;
      }

      return `${panelHref}/preview/changes`;
    },
    itemDialogPath(item, action) {
      const panelPath = this.itemPanelPath(item);

      if (panelPath === null) {
        return null;
      }

      return `${panelPath.slice(1)}/${action}`;
    },
    hasItemOptions(item) {
      return this.move == null &&
        typeof item?.id === "string" &&
        item.id !== "";
    },
    featureFlagParts(item) {
      if (Array.isArray(item.flagParts) === true) {
        return item.flagParts;
      }

      const flag = this.itemFeatureFlag(item);
      return typeof flag === "string" && flag !== ""
        ? [{ text: flag, match: false }]
        : [];
    },
    itemFeatureFlag(item) {
      if (typeof item.featureFlag === "string" && item.featureFlag !== "") {
        return item.featureFlag;
      }

      return null;
    },
    itemTitle(item) {
      const featureFlag = this.itemFeatureFlag(item);

      if (typeof item.title === "string" && item.title !== "") {
        return stripFeatureFlagTitlePrefix(item.title, featureFlag);
      }

      return stripFeatureFlagTitlePrefix(item.label, featureFlag);
    },
    labelParts(item) {
      const featureFlag = this.itemFeatureFlag(item);

      return Array.isArray(item.titleParts) === true
        ? stripFeatureFlagTitleParts(item.titleParts, featureFlag)
        : [{ text: this.itemTitle(item), match: false }];
    },
    async load(path) {
      if (path == null) {
        path = this.$attrs.parent ?? window.panel.view.path;
      }

      try {
        return this.normalizeItems(await this.originalLoad(path));
      } catch (error) {
        this.didLoadError = true;
        window.panel.error("Error loading the menu pages! Make sure that your rootPage is correct in your blueprint !", false);
        return [];
      }
    },
    open(item) {
      if (!item || item.hasChildren === false) {
        return false;
      }

      this.$set(item, "loading", true);

      if (typeof item.children === "string") {
        return this.originalOpen(item);
      }

      this.$set(item, "open", true);
      this.$set(item, "loading", false);
      this.$emit("open", item);
      return true;
    },
    async restoreExpandedItems(items = this.state) {
      if (this.restoreExpanded !== true || Array.isArray(items) !== true) {
        return;
      }

      for (const item of items) {
        if (this.shouldRestoreExpandedItem(item) !== true) {
          continue;
        }

        await this.open(item);
      }
    },
    shouldRestoreExpandedItem(item) {
      return item?.hasChildren === true &&
        item?.open !== true &&
        typeof item?.id === "string" &&
        item.id !== "" &&
        this.expandedLookup[item.id] === true;
    },
    originalLoad(path) {
      return this.$api.get("arborescence/children", {
        branchSorts: JSON.stringify(this.branchSorts ?? {}),
        move: this.move ?? null,
        parent: path,
      }, null, true);
    },
    async originalOpen(item) {
      item.children = await this.load(item.children);
      this.$set(item, "open", true);
      this.$set(item, "loading", false);
      this.$emit("open", item);
      return true;
    },
    createChild(item) {
      const parent = this.itemPanelPath(item);
      const view = typeof window?.panel?.view?.path === "string" && window.panel.view.path !== ""
        ? window.panel.view.path
        : null;
      const query = {};

      if (parent === null) {
        return;
      }

      query.parent = parent;

      if (view !== null) {
        query.view = view;
      }

      this.$panel.dialog.open("pages/create", {
        query,
      });
    },
    openItemDialog(item, action) {
      const path = this.itemDialogPath(item, action);

      if (path === null) {
        return;
      }

      this.$panel.dialog.open(path);
    },
    openItemDialogWithQuery(item, action, query) {
      const path = this.itemDialogPath(item, action);

      if (path === null) {
        return;
      }

      this.$panel.dialog.open(path, {
        query,
      });
    },
    itemOptions(item) {
      const options = [];
      const openUrl = typeof item?.openUrl === "string" && item.openUrl !== ""
        ? item.openUrl
        : null;
      const previewUrl = this.itemPreviewHref(item);

      options.push({
        disabled: openUrl === null,
        icon: "open",
        link: openUrl,
        target: "_blank",
        text: this.$t("open"),
      });

      options.push({
        disabled: openUrl === null || previewUrl === null,
        icon: "window",
        link: previewUrl,
        text: this.$t("preview"),
      });

      options.push("-");

      options.push({
        click: () => this.openItemDialogWithQuery(item, "changeTitle", { select: "title" }),
        disabled: item?.canChangeTitle !== true,
        icon: "title",
        text: this.$t("rename"),
      });

      options.push({
        click: () => this.openItemDialogWithQuery(item, "changeTitle", { select: "slug" }),
        disabled: item?.canChangeSlug !== true,
        icon: "url",
        text: this.$t("page.changeSlug"),
      });

      options.push({
        click: () => this.openItemDialog(item, "changeStatus"),
        disabled: item?.canChangeStatus !== true,
        icon: "preview",
        text: this.$t("page.changeStatus"),
      });

      options.push({
        click: () => this.openItemDialog(item, "changeSort"),
        disabled: item?.canChangeSort !== true,
        icon: "sort",
        text: this.$t("page.sort"),
      });

      options.push("-");

      options.push({
        click: () => this.createChild(item),
        disabled: item?.canCreate !== true,
        icon: "add",
        text: "New child page",
      });

      options.push({
        click: () => this.openItemDialog(item, "duplicate"),
        disabled: item?.canDuplicate !== true,
        icon: "copy",
        text: this.$t("duplicate"),
      });

      options.push("-");

      options.push({
        click: () => this.openItemDialog(item, "delete"),
        disabled: item?.canDelete !== true,
        icon: "trash",
        text: this.$t("delete"),
      });

      return options;
    },
    pathParts(item) {
      if (this.showPaths !== true) {
        return [];
      }

      if (Array.isArray(item.pathParts) === true && item.pathParts.length > 0) {
        return item.pathParts;
      }

      if (typeof item?.path === "string" && item.path !== "") {
        return [{ text: item.path, match: false }];
      }

      return [];
    },
    statusIcon(item) {
      switch (item?.status) {
      case "draft":
        return "status-draft";
      case "unlisted":
        return "status-unlisted";
      default:
        return null;
      }
    },
    statusTitle(item) {
      if (item?.status === "draft" || item?.status === "unlisted") {
        return this.$t(`page.status.${item.status}`);
      }

      return null;
    },
    normalizeItem(item) {
      if (!item || typeof item !== "object") {
        return item;
      }

      const parsedLabel = parseFeatureFlagLabel(item.label);
      const featureFlag = typeof item.featureFlag === "string" && item.featureFlag !== ""
        ? item.featureFlag
        : parsedLabel?.flag ?? null;
      const title = stripFeatureFlagTitlePrefix(
        typeof item.title === "string" && item.title !== ""
          ? item.title
          : parsedLabel?.title ?? item.label,
        featureFlag
      );
      const titleParts = stripFeatureFlagTitleParts(item.titleParts, featureFlag);
      const children = Array.isArray(item.children) === true
        ? this.normalizeItems(item.children)
        : item.children;

      return {
        ...item,
        children,
        featureFlag,
        title,
        titleParts,
      };
    },
    normalizeItems(items) {
      if (Array.isArray(items) === true) {
        return items.map((item) => this.normalizeItem(item));
      }

      if (items && typeof items === "object") {
        return this.normalizeItem(items);
      }

      return [];
    },
    async preselect(page) {
      const response = await this.$panel.get("site/tree/parents", {
        query: {
          page,
          root: this.root,
        },
      });
      const parents = response.data;
      let tree = this;

      for (let index = 0; index < parents.length; index++) {
        const value = parents[index];
        const item = tree.findItem(value);

        if (!item) {
          return;
        }

        await this.open(item);
        tree = tree.$refs[value][0];
      }

      const item = tree.findItem(page);
      if (item) {
        this.$emit("select", item);
      }
    },
    select(item, event = null) {
      if (event && this.isPlainLeftClick(event) !== true) {
        return;
      }

      event?.preventDefault();
      this.$emit("select", item);
    },
    toggle(item) {
      this.$emit("toggle", item);

      if (item.open === true) {
        this.close(item);
      } else {
        this.open(item);
      }
    },
  },
};
</script>

<style>
.page-tree-menu .k-tree-branch {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.25rem;
}

.page-tree-menu .k-tree-folder {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  min-width: 0;
  text-align: left;
}

.page-tree-menu .k-tree-branch-options {
  justify-self: end;
}

.page-tree-menu .k-tree-folder-copy {
  grid-column: 2;
  min-width: 0;
  overflow: hidden;
}

.page-tree-menu .k-tree-folder-copy.k-tree-folder-copy-with-path {
  display: flex;
  inline-size: 100%;
  gap: 0.5rem;
  align-items: baseline;
  justify-content: flex-start;
}

.page-tree-menu .k-tree-folder-copy.k-tree-folder-copy-with-path .k-tree-folder-label {
  flex: 0 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.page-tree-menu .k-tree-folder-copy.k-tree-folder-copy-with-path .k-tree-folder-path {
  flex: 0 1 auto;
  max-inline-size: min(22rem, 40vw);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-text-dimmed);
  font-size: var(--text-xs);
}

.page-tree-menu .k-tree-folder-status {
  color: var(--color-text-dimmed);
  grid-column: 3;
  justify-self: end;
}

.page-tree-menu .k-tree-folder-status .k-icon {
  --icon-size: 15px;
}

.page-tree-menu .k-tree-match {
  background: light-dark(var(--color-yellow-250), var(--color-yellow-850));
  box-decoration-break: clone;
  -webkit-box-decoration-break: clone;
}
</style>
