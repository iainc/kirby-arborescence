export function openCreateChildDialog({
  panel,
  query,
}) {
  window.setTimeout(() => {
    panel.dialog.open("pages/create", {
      query,
    });
  }, 0);
}
