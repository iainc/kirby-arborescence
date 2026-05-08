function normalizeTemplate(value) {
  if (typeof value !== "string") {
    return null;
  }

  const template = value.trim();
  return template !== "" ? template : null;
}

function normalizeTemplateOptions(options = []) {
  if (Array.isArray(options) !== true) {
    return [];
  }

  const seen = new Set();
  const normalized = [];

  for (const option of options) {
    const value = normalizeTemplate(option?.name ?? option?.value);

    if (value === null || seen.has(value) === true) {
      continue;
    }

    const title = normalizeTemplate(option?.title ?? option?.text);

    normalized.push({
      text: title !== null && title !== value ? `${title} (${value})` : (title ?? value),
      value,
    });

    seen.add(value);
  }

  return normalized;
}

function openKirbyCreateDialog(panel, query) {
  window.setTimeout(() => {
    panel.dialog.open("pages/create", {
      query,
    });
  }, 0);
}

function openMissingTemplateDialog(panel) {
  panel.dialog.open({
    component: "k-error-dialog",
    props: {
      message: "This page doesn't define any allowed child templates. Update the tree configuration or blueprint before creating a child page.",
    },
    on: {
      cancel: () => {
        panel.dialog.close();
      },
    },
  });
}

export function openCreateChildDialog({
  panel,
  query,
  templateOptions = [],
}) {
  const options = normalizeTemplateOptions(templateOptions);

  if (options.length === 0) {
    openMissingTemplateDialog(panel);
    return;
  }

  if (options.length === 1) {
    openKirbyCreateDialog(panel, {
      ...query,
      template: options[0].value,
    });
    return;
  }

  const allowedTemplates = new Set(options.map((option) => option.value));

  panel.dialog.open({
    component: "k-form-dialog",
    props: {
      fields: {
        template: {
          label: "Template",
          options,
          placeholder: "Choose a template",
          required: true,
          type: "select",
        },
      },
      size: "medium",
      submitButton: {
        icon: "add",
        text: "Continue",
      },
      value: {
        template: null,
      },
    },
    on: {
      cancel: () => {
        panel.dialog.close();
      },
      submit: (payload = {}) => {
        const nextQuery = {
          ...query,
        };
        const nextTemplate = normalizeTemplate(payload?.template);

        if (nextTemplate === null || allowedTemplates.has(nextTemplate) !== true) {
          panel.notification.error("Select a valid template to continue.");
          return;
        }

        nextQuery.template = nextTemplate;
        panel.dialog.close();
        openKirbyCreateDialog(panel, nextQuery);
      },
    },
  });
}
