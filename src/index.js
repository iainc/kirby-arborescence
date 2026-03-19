import Arborescence from "./components/Arborescence.vue";
import ArborescenceDialog from "./components/ArborescenceDialog.vue";
import PageTreeMenu from "./components/PageTreeMenu.vue";

panel.plugin("daandelange/arborescence", {
  components: {
    "k-arborescence": Arborescence,
    "k-arborescence-search-dialog": ArborescenceDialog,
    "page-tree-menu": PageTreeMenu,
  },
  sections: {
    arborescence: Arborescence
  }
});
