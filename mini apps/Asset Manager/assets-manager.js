(function () {
  let assetsManagerInitialized = false;
  const ASSETS_MANAGER_STATE = {
    storages: [],
    labels: [],
    ancestors: [],
    assets: []
  };
  const DEFAULT_ENDPOINT = "mini%20apps/Asset%20Manager/index.php";

  const qs = (selector, root) => (root || document).querySelector(selector);
  const qsa = (selector, root) => Array.from((root || document).querySelectorAll(selector));

  const byId = (items, id) => items.find((item) => String(item?.id || "") === String(id || "")) || null;

  const getChildLabels = (parentId) => {
    const parent = String(parentId || "");
    return ASSETS_MANAGER_STATE.labels.filter((label) => String(label?.parent_id || "") === parent);
  };

  const labelHasChildren = (labelId) => {
    const id = String(labelId || "");
    return ASSETS_MANAGER_STATE.labels.some((label) => String(label?.parent_id || "") === id);
  };

  const getRootParentLabels = () => {
    return ASSETS_MANAGER_STATE.labels.filter((label) => {
      const id = String(label?.id || "");
      const parentId = String(label?.parent_id || "");
      return parentId === "" && labelHasChildren(id);
    });
  };

  const getChildParentLabels = (parentId) => {
    return getChildLabels(parentId).filter((label) => labelHasChildren(String(label?.id || "")));
  };

  const labelPathName = (labelId) => {
    const id = String(labelId || "");
    if (!id) return "";

    const chain = [];
    let current = byId(ASSETS_MANAGER_STATE.labels, id);
    const seen = new Set();

    while (current && !seen.has(String(current.id || ""))) {
      const currentId = String(current.id || "");
      seen.add(currentId);
      chain.unshift(String(current.name || ""));
      const parentId = String(current.parent_id || "");
      if (!parentId) break;
      current = byId(ASSETS_MANAGER_STATE.labels, parentId);
    }

    return chain.join(" / ");
  };

  function initAssetsManagerTab(options) {
    if (assetsManagerInitialized) {
      return;
    }

    const root = qs("[data-pm-root]");
    if (!root) {
      return;
    }

    assetsManagerInitialized = true;

    const endpoint = typeof options?.endpoint === "string" && options.endpoint.trim()
      ? options.endpoint
      : DEFAULT_ENDPOINT;

    const paneButtons = qsa("[data-pm-pane-target]", root);
    const panes = qsa("[data-pm-pane]", root);

    const assetForm = qs("#pm-add-asset-form", root);
    const storageForm = qs("#pm-add-storage-form", root);
    const ancestorForm = qs("#pm-add-ancestor-form", root);
    const labelForm = qs("#pm-add-label-form", root);

    const assetSpecialToggle = qs("#pm-asset-special", root);
    const assetAncestorField = qs("#pm-asset-ancestor-field", root);
    const assetNameField = qs("#pm-asset-name-field", root);
    const assetAncestorSelect = qs("#pm-asset-ancestor", root);
    const assetNameInput = qs("#pm-asset-name", root);
    const assetCodeInput = qs("#pm-asset-code", root);
    const assetStorageSelect = qs("#pm-asset-storage", root);
    const assetLabelValuesWrap = qs("#pm-asset-label-values", root);

    const storageNameInput = qs("#pm-storage-name", root);
    const ancestorNameInput = qs("#pm-ancestor-name", root);
    const ancestorLabelChain = qs("#pm-ancestor-label-chain", root);

    const labelNameInput = qs("#pm-label-name", root);
    const labelParentSelect = qs("#pm-label-parent", root);

    const assetStatus = qs("#pm-asset-status", root);
    const storageStatus = qs("#pm-storage-status", root);
    const ancestorStatus = qs("#pm-ancestor-status", root);
    const labelStatus = qs("#pm-label-status", root);

    const assetsBody = qs("#pm-assets-body", root);
    const storagesBody = qs("#pm-storages-body", root);
    const ancestorsBody = qs("#pm-ancestors-body", root);
    const labelsBody = qs("#pm-labels-body", root);
    const ancestorLabelsModal = qs("#pm-ancestor-labels-modal");
    const ancestorLabelsModalTitle = qs("#pm-ancestor-labels-title");
    const ancestorLabelsModalChain = qs("#pm-ancestor-modal-label-chain");
    const ancestorLabelsModalStatus = qs("#pm-ancestor-modal-status");
    const ancestorLabelsModalSave = qs("#pm-ancestor-modal-save");
    const ancestorLabelsModalCloseButtons = qsa("[data-pm-ancestor-modal-close]", ancestorLabelsModal || document);
    const assetLabelsModal = qs("#pm-asset-labels-modal");
    const assetLabelsModalTitle = qs("#pm-asset-labels-title");
    const assetLabelsModalFields = qs("#pm-asset-modal-label-fields");
    const assetLabelsModalStatus = qs("#pm-asset-modal-status");
    const assetLabelsModalSave = qs("#pm-asset-modal-save");
    const assetLabelsModalCloseButtons = qsa("[data-pm-asset-modal-close]", assetLabelsModal || document);

    let editingAncestorLabelsId = "";
    let editingAssetLabelsId = "";

    const setStatus = (element, message, isError = false) => {
      if (!element) return;
      element.textContent = message || "";
      element.classList.toggle("error", Boolean(isError));
    };

    const setPane = (paneId) => {
      paneButtons.forEach((button) => {
        button.classList.toggle("active", button.dataset.pmPaneTarget === paneId);
      });
      panes.forEach((pane) => {
        pane.classList.toggle("active", pane.dataset.pmPane === paneId);
      });
    };

    const isSpecialAsset = (item) => {
      return item?.special_asset === true || String(item?.special_asset || "") === "1";
    };
    const fillStorageOptions = (select, selected = "") => {
      if (!select) return;
      const selectedId = String(selected || "");
      select.innerHTML = "";
      const emptyOption = document.createElement("option");
      emptyOption.value = "";
      emptyOption.textContent = ASSETS_MANAGER_STATE.storages.length ? "انتخاب انبار" : "انباری ثبت نشده";
      select.appendChild(emptyOption);

      ASSETS_MANAGER_STATE.storages.forEach((storage) => {
        const option = document.createElement("option");
        option.value = String(storage.id || "");
        option.textContent = String(storage.name || "");
        if (option.value === selectedId) {
          option.selected = true;
        }
        select.appendChild(option);
      });

      select.disabled = ASSETS_MANAGER_STATE.storages.length === 0;
    };

    const fillAncestorOptions = (select, selected = "") => {
      if (!select) return;
      const selectedId = String(selected || "");
      select.innerHTML = "";
      const emptyOption = document.createElement("option");
      emptyOption.value = "";
      emptyOption.textContent = ASSETS_MANAGER_STATE.ancestors.length ? "انتخاب مال مرسوم" : "مال مرسومی ثبت نشده";
      select.appendChild(emptyOption);

      ASSETS_MANAGER_STATE.ancestors.forEach((ancestor) => {
        const option = document.createElement("option");
        option.value = String(ancestor.id || "");
        option.textContent = String(ancestor.name || "");
        if (option.value === selectedId) {
          option.selected = true;
        }
        select.appendChild(option);
      });

      select.disabled = ASSETS_MANAGER_STATE.ancestors.length === 0;
    };

    const fillLabelParentOptions = (select, selected = "", exceptId = "") => {
      if (!select) return;
      const selectedId = String(selected || "");
      const excludedId = String(exceptId || "");
      select.innerHTML = "";

      const emptyOption = document.createElement("option");
      emptyOption.value = "";
      emptyOption.textContent = "بدون والد";
      select.appendChild(emptyOption);

      const sortedLabels = [...ASSETS_MANAGER_STATE.labels].sort((a, b) => {
        return labelPathName(a.id).localeCompare(labelPathName(b.id), "fa");
      });

      sortedLabels.forEach((label) => {
        const labelId = String(label.id || "");
        if (!labelId || labelId === excludedId) return;

        const option = document.createElement("option");
        option.value = labelId;
        option.textContent = labelPathName(labelId) || String(label.name || "");
        if (labelId === selectedId) {
          option.selected = true;
        }
        select.appendChild(option);
      });
    };

const readLabelChainSelected = (container) => {
      if (!container) return [];
      const raw = String(container.dataset.selected || "").trim();
      if (!raw) return [];
      try {
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
          return [];
        }
        return parsed.map((id) => String(id || "").trim()).filter(Boolean);
      } catch (error) {
        return [];
      }
    };

    const collectAncestorChainSelected = () => {
      return readLabelChainSelected(ancestorLabelChain);
    };

    const renderLabelChainPicker = (container, selectedIds = []) => {
      if (!container) return;
      container.innerHTML = "";

      const requested = Array.isArray(selectedIds)
        ? selectedIds.map((id) => String(id || "").trim()).filter(Boolean)
        : [];

      const finalSelected = [];
      let parentId = "";
      requested.forEach((candidateId, index) => {
        const options = index === 0 ? getRootParentLabels() : getChildParentLabels(parentId);
        if (!options.some((label) => String(label.id || "") === candidateId)) {
          return;
        }
        finalSelected.push(candidateId);
        parentId = candidateId;
      });

      container.dataset.selected = JSON.stringify(finalSelected);

      const wrapper = document.createElement("div");
      wrapper.className = "field";

      const caption = document.createElement("span");
      caption.textContent = "\u0632\u0646\u062C\u06CC\u0631\u0647 \u0628\u0631\u0686\u0633\u0628";

      const chips = document.createElement("div");
      chips.className = "pm-label-chip-list";

      if (!finalSelected.length) {
        const emptyHint = document.createElement("p");
        emptyHint.className = "hint";
        emptyHint.textContent = "\u0647\u0646\u0648\u0632 \u0628\u0631\u0686\u0633\u0628\u06CC \u0627\u0646\u062A\u062E\u0627\u0628 \u0646\u0634\u062F\u0647 \u0627\u0633\u062A.";
        chips.appendChild(emptyHint);
      } else {
        finalSelected.forEach((labelId, index) => {
          const label = byId(ASSETS_MANAGER_STATE.labels, labelId);
          if (!label) return;

          const chip = document.createElement("span");
          chip.className = "pm-label-chip";

          const chipText = document.createElement("span");
          chipText.textContent = String(label.name || "");

          const removeButton = document.createElement("button");
          removeButton.type = "button";
          removeButton.className = "pm-label-chip-remove";
          removeButton.setAttribute("aria-label", "\u062D\u0630\u0641 \u0628\u0631\u0686\u0633\u0628");
          removeButton.textContent = "x";
          removeButton.addEventListener("click", () => {
            const nextSelected = finalSelected.slice(0, index);
            renderLabelChainPicker(container, nextSelected);
          });

          chip.append(chipText, removeButton);
          chips.appendChild(chip);
        });
      }

      const nextParentId = finalSelected.length
        ? String(finalSelected[finalSelected.length - 1] || "")
        : "";
      const nextOptions = finalSelected.length
        ? getChildParentLabels(nextParentId)
        : getRootParentLabels();

      const addField = document.createElement("label");
      addField.className = "field";

      const addCaption = document.createElement("span");
      addCaption.textContent = "\u0627\u0641\u0632\u0648\u062F\u0646 \u0628\u0631\u0686\u0633\u0628";

      const select = document.createElement("select");
      const emptyOption = document.createElement("option");
      emptyOption.value = "";
      emptyOption.textContent = nextOptions.length
        ? "\u0627\u0646\u062A\u062E\u0627\u0628 \u0628\u0631\u0686\u0633\u0628"
        : "\u0628\u0631\u0686\u0633\u0628 \u0642\u0627\u0628\u0644 \u0627\u0646\u062A\u062E\u0627\u0628\u06CC \u0648\u062C\u0648\u062F \u0646\u062F\u0627\u0631\u062F";
      select.appendChild(emptyOption);

      nextOptions.forEach((label) => {
        const option = document.createElement("option");
        option.value = String(label.id || "");
        option.textContent = String(label.name || "");
        select.appendChild(option);
      });

      select.disabled = nextOptions.length === 0;
      select.addEventListener("change", () => {
        const picked = String(select.value || "").trim();
        if (!picked) return;
        renderLabelChainPicker(container, [...finalSelected, picked]);
      });

      addField.append(addCaption, select);
      wrapper.append(caption, chips, addField);
      container.appendChild(wrapper);
    };

    const renderAncestorLabelChain = (selectedIds = []) => {
      renderLabelChainPicker(ancestorLabelChain, selectedIds);
    };

    const collectAssetLabelValues = () => {
      if (!assetLabelValuesWrap) return {};
      const payload = {};
      qsa("select[data-asset-label-key]", assetLabelValuesWrap).forEach((select) => {
        const key = String(select.dataset.assetLabelKey || "").trim();
        if (!key) return;
        payload[key] = String(select.value || "").trim();
      });
      return payload;
    };

    const renderAssetLabelFields = () => {
      if (!assetLabelValuesWrap) return;
      assetLabelValuesWrap.innerHTML = "";

      const special = Boolean(assetSpecialToggle?.checked);
      const ancestorId = String(assetAncestorSelect?.value || "").trim();
      if (special || !ancestorId) {
        assetLabelValuesWrap.classList.add("hidden");
        return;
      }

      const ancestor = byId(ASSETS_MANAGER_STATE.ancestors, ancestorId);
      const labelIds = Array.isArray(ancestor?.label_ids)
        ? ancestor.label_ids.map((id) => String(id || "")).filter(Boolean)
        : [];

      if (!labelIds.length) {
        assetLabelValuesWrap.classList.add("hidden");
        return;
      }

      const previous = collectAssetLabelValues();
      let renderedFields = 0;

      labelIds.forEach((labelId, index) => {
        const sourceLabel = byId(ASSETS_MANAGER_STATE.labels, labelId);
        const options = getChildLabels(labelId);
        if (!options.length) {
          return;
        }

        const field = document.createElement("label");
        field.className = "field";

        const caption = document.createElement("span");
        caption.textContent = String(sourceLabel?.name || `برچسب ${index + 1}`);

        const select = document.createElement("select");
        select.dataset.assetLabelKey = labelId;

        const emptyOption = document.createElement("option");
        emptyOption.value = "";
        emptyOption.textContent = "اختیاری";
        select.appendChild(emptyOption);

        options.forEach((label) => {
          const option = document.createElement("option");
          option.value = String(label.id || "");
          option.textContent = labelPathName(label.id) || String(label.name || "");
          select.appendChild(option);
        });

        const selectedValue = String(previous[labelId] || "");
        if (selectedValue) {
          select.value = selectedValue;
        }

        field.append(caption, select);
        assetLabelValuesWrap.appendChild(field);
        renderedFields += 1;
      });

      if (!renderedFields) {
        assetLabelValuesWrap.classList.add("hidden");
        return;
      }

      assetLabelValuesWrap.classList.remove("hidden");
    };

    const setSpecialMode = (isSpecial) => {
      assetAncestorField?.classList.toggle("hidden", isSpecial);
      assetNameField?.classList.toggle("hidden", !isSpecial);

      if (assetAncestorSelect) {
        assetAncestorSelect.disabled = isSpecial || ASSETS_MANAGER_STATE.ancestors.length === 0;
        assetAncestorSelect.required = !isSpecial;
        if (isSpecial) {
          assetAncestorSelect.value = "";
        }
      }

      if (assetNameInput) {
        assetNameInput.disabled = !isSpecial;
        assetNameInput.required = isSpecial;
        if (!isSpecial) {
          assetNameInput.value = "";
        }
      }

      renderAssetLabelFields();
    };

    const closeAncestorLabelsModal = () => {
      if (!ancestorLabelsModal) return;
      ancestorLabelsModal.classList.add("hidden");
      editingAncestorLabelsId = "";
      if (ancestorLabelsModalChain) {
        ancestorLabelsModalChain.innerHTML = "";
        ancestorLabelsModalChain.dataset.selected = "[]";
      }
      setStatus(ancestorLabelsModalStatus, "");
    };

    const openAncestorLabelsModal = (ancestorId) => {
      if (!ancestorLabelsModal || !ancestorLabelsModalChain) return;
      const ancestor = byId(ASSETS_MANAGER_STATE.ancestors, ancestorId);
      if (!ancestor) return;

      editingAncestorLabelsId = String(ancestor.id || "");
      const ancestorName = String(ancestor.name || "");
      if (ancestorLabelsModalTitle) {
        ancestorLabelsModalTitle.textContent = `\u0628\u0631\u0686\u0633\u0628\u200C\u0647\u0627\u06CC ${ancestorName}`;
      }

      const selectedIds = Array.isArray(ancestor.label_ids)
        ? ancestor.label_ids.map((id) => String(id || "")).filter(Boolean)
        : [];
      renderLabelChainPicker(ancestorLabelsModalChain, selectedIds);
      setStatus(ancestorLabelsModalStatus, "");
      ancestorLabelsModal.classList.remove("hidden");
    };

    const getAncestorParentLabelIds = (ancestorId) => {
      const ancestor = byId(ASSETS_MANAGER_STATE.ancestors, ancestorId);
      if (!ancestor || !Array.isArray(ancestor.label_ids)) {
        return [];
      }
      return ancestor.label_ids.map((id) => String(id || "").trim()).filter(Boolean);
    };

    const formatAssetLabelsSummary = (asset, parentLabelIds) => {
      if (isSpecialAsset(asset) || !parentLabelIds.length) {
        return "\u0646\u062F\u0627\u0631\u062F";
      }

      const values = asset && typeof asset.label_values === "object" && asset.label_values !== null
        ? asset.label_values
        : {};
      const parts = [];

      parentLabelIds.forEach((parentId) => {
        const childId = String(values[parentId] || "").trim();
        if (!childId) {
          return;
        }
        const parentName = String(byId(ASSETS_MANAGER_STATE.labels, parentId)?.name || "");
        const childName = labelPathName(childId) || String(byId(ASSETS_MANAGER_STATE.labels, childId)?.name || "");
        if (!childName) {
          return;
        }
        parts.push(parentName ? `${parentName}: ${childName}` : childName);
      });

      return parts.length ? parts.join(" | ") : "\u062A\u0639\u06CC\u06CC\u0646 \u0646\u0634\u062F\u0647";
    };

    const closeAssetLabelsModal = () => {
      if (!assetLabelsModal) return;
      assetLabelsModal.classList.add("hidden");
      editingAssetLabelsId = "";
      if (assetLabelsModalFields) {
        assetLabelsModalFields.innerHTML = "";
      }
      setStatus(assetLabelsModalStatus, "");
    };

    const readAssetModalLabelValues = () => {
      if (!assetLabelsModalFields) return {};
      const payload = {};
      qsa("select[data-asset-modal-label-key]", assetLabelsModalFields).forEach((select) => {
        const key = String(select.dataset.assetModalLabelKey || "").trim();
        if (!key) return;
        payload[key] = String(select.value || "").trim();
      });
      return payload;
    };

    const openAssetLabelsModal = (assetId) => {
      if (!assetLabelsModal || !assetLabelsModalFields) return;
      const asset = byId(ASSETS_MANAGER_STATE.assets, assetId);
      if (!asset || isSpecialAsset(asset)) return;

      const parentLabelIds = getAncestorParentLabelIds(String(asset.ancestor_id || ""));
      if (!parentLabelIds.length) return;

      editingAssetLabelsId = String(asset.id || "");
      const titleRef = String(asset.code || asset.name || "");
      if (assetLabelsModalTitle) {
        assetLabelsModalTitle.textContent = `\u0628\u0631\u0686\u0633\u0628\u200C\u0647\u0627\u06CC \u0645\u0627\u0644 ${titleRef}`;
      }

      const currentValues = asset && typeof asset.label_values === "object" && asset.label_values !== null
        ? asset.label_values
        : {};

      assetLabelsModalFields.innerHTML = "";
      parentLabelIds.forEach((parentId, index) => {
        const options = getChildLabels(parentId);
        if (!options.length) {
          return;
        }

        const field = document.createElement("label");
        field.className = "field";

        const caption = document.createElement("span");
        const parentName = String(byId(ASSETS_MANAGER_STATE.labels, parentId)?.name || `\u0628\u0631\u0686\u0633\u0628 ${index + 1}`);
        caption.textContent = parentName;

        const select = document.createElement("select");
        select.dataset.assetModalLabelKey = parentId;

        const emptyOption = document.createElement("option");
        emptyOption.value = "";
        emptyOption.textContent = "\u0627\u062E\u062A\u06CC\u0627\u0631\u06CC";
        select.appendChild(emptyOption);

        options.forEach((label) => {
          const option = document.createElement("option");
          option.value = String(label.id || "");
          option.textContent = labelPathName(label.id) || String(label.name || "");
          select.appendChild(option);
        });

        const selectedValue = String(currentValues[parentId] || "");
        if (selectedValue) {
          select.value = selectedValue;
        }

        field.append(caption, select);
        assetLabelsModalFields.appendChild(field);
      });

      if (!assetLabelsModalFields.children.length) {
        const hint = document.createElement("p");
        hint.className = "hint";
        hint.textContent = "\u0628\u0631\u0686\u0633\u0628 \u0642\u0627\u0628\u0644 \u0627\u0646\u062A\u062E\u0627\u0628\u06CC \u0648\u062C\u0648\u062F \u0646\u062F\u0627\u0631\u062F.";
        assetLabelsModalFields.appendChild(hint);
      }

      setStatus(assetLabelsModalStatus, "");
      assetLabelsModal.classList.remove("hidden");
    };

    const renderAssets = () => {
      if (!assetsBody) return;
      assetsBody.innerHTML = "";
      if (!ASSETS_MANAGER_STATE.assets.length) {
        const row = document.createElement("tr");
        row.innerHTML = '<td class="empty" colspan="5">\u0645\u0627\u0644\u06CC \u062B\u0628\u062A \u0646\u0634\u062F\u0647 \u0627\u0633\u062A.</td>';
        assetsBody.appendChild(row);
        return;
      }

      ASSETS_MANAGER_STATE.assets.forEach((asset) => {
        const row = document.createElement("tr");
        row.dataset.id = String(asset.id || "");

        const titleCell = document.createElement("td");
        if (isSpecialAsset(asset)) {
          const nameInput = document.createElement("input");
          nameInput.type = "text";
          nameInput.dataset.field = "name";
          nameInput.value = String(asset.name || "");
          titleCell.appendChild(nameInput);
        } else {
          const ancestorSelect = document.createElement("select");
          ancestorSelect.dataset.field = "ancestor_id";
          fillAncestorOptions(ancestorSelect, String(asset.ancestor_id || ""));
          titleCell.appendChild(ancestorSelect);
        }

        const codeCell = document.createElement("td");
        const codeInput = document.createElement("input");
        codeInput.type = "text";
        codeInput.dataset.field = "code";
        codeInput.value = String(asset.code || "");
        codeCell.appendChild(codeInput);

        const storageCell = document.createElement("td");
        const storageSelect = document.createElement("select");
        storageSelect.dataset.field = "storage_id";
        fillStorageOptions(storageSelect, String(asset.storage_id || ""));
        storageCell.appendChild(storageSelect);

        const labelsCell = document.createElement("td");
        const labelsSummary = document.createElement("p");
        labelsSummary.className = "hint";
        labelsSummary.style.margin = "0 0 8px 0";
        labelsSummary.style.textAlign = "right";

        const labelsButton = document.createElement("button");
        labelsButton.type = "button";
        labelsButton.className = "btn ghost";
        labelsButton.dataset.action = "edit-asset-labels";
        labelsButton.textContent = "\u0628\u0631\u0686\u0633\u0628\u200C\u0647\u0627";

        const parentLabelIds = isSpecialAsset(asset)
          ? []
          : getAncestorParentLabelIds(String(asset.ancestor_id || ""));
        labelsSummary.textContent = formatAssetLabelsSummary(asset, parentLabelIds);
        if (isSpecialAsset(asset) || !parentLabelIds.length) {
          labelsButton.disabled = true;
        }

        labelsCell.append(labelsSummary, labelsButton);

        const actionCell = document.createElement("td");
        actionCell.className = "pm-actions-cell";
        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "btn ghost";
        removeButton.dataset.action = "remove-asset";
        removeButton.textContent = "\u062D\u0630\u0641";
        actionCell.appendChild(removeButton);

        row.append(titleCell, codeCell, storageCell, labelsCell, actionCell);
        assetsBody.appendChild(row);
      });
    };

    const renderStorages = () => {
      if (!storagesBody) return;
      storagesBody.innerHTML = "";
      if (!ASSETS_MANAGER_STATE.storages.length) {
        const row = document.createElement("tr");
        row.innerHTML = '<td class="empty" colspan="2">انباری ثبت نشده است.</td>';
        storagesBody.appendChild(row);
        return;
      }

      ASSETS_MANAGER_STATE.storages.forEach((storage) => {
        const row = document.createElement("tr");
        row.dataset.id = String(storage.id || "");

        const nameCell = document.createElement("td");
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.value = String(storage.name || "");
        nameInput.dataset.field = "name";
        nameCell.appendChild(nameInput);

        const actionCell = document.createElement("td");
        actionCell.className = "pm-actions-cell";
        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "btn ghost";
        removeButton.dataset.action = "remove-storage";
        removeButton.textContent = "حذف";
        actionCell.appendChild(removeButton);

        row.append(nameCell, actionCell);
        storagesBody.appendChild(row);
      });
    };

    const renderAncestors = () => {
      if (!ancestorsBody) return;
      ancestorsBody.innerHTML = "";
      if (!ASSETS_MANAGER_STATE.ancestors.length) {
        const row = document.createElement("tr");
        row.innerHTML = '<td class="empty" colspan="3">\u0645\u0627\u0644 \u0645\u0631\u0633\u0648\u0645\u06CC \u062B\u0628\u062A \u0646\u0634\u062F\u0647 \u0627\u0633\u062A.</td>';
        ancestorsBody.appendChild(row);
        return;
      }

      ASSETS_MANAGER_STATE.ancestors.forEach((ancestor) => {
        const row = document.createElement("tr");
        row.dataset.id = String(ancestor.id || "");

        const nameCell = document.createElement("td");
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.value = String(ancestor.name || "");
        nameInput.dataset.field = "name";
        nameCell.appendChild(nameInput);

        const labelsCell = document.createElement("td");
        labelsCell.className = "pm-actions-cell";
        const labelsButton = document.createElement("button");
        labelsButton.type = "button";
        labelsButton.className = "btn ghost";
        labelsButton.dataset.action = "edit-ancestor-labels";
        labelsButton.textContent = "\u0628\u0631\u0686\u0633\u0628\u200C\u0647\u0627";
        labelsCell.appendChild(labelsButton);

        const actionCell = document.createElement("td");
        actionCell.className = "pm-actions-cell";
        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "btn ghost";
        removeButton.dataset.action = "remove-ancestor";
        removeButton.textContent = "\u062D\u0630\u0641";
        actionCell.appendChild(removeButton);

        row.append(nameCell, labelsCell, actionCell);
        ancestorsBody.appendChild(row);
      });
    };

    const renderLabels = () => {
      if (!labelsBody) return;
      labelsBody.innerHTML = "";
      if (!ASSETS_MANAGER_STATE.labels.length) {
        const row = document.createElement("tr");
        row.innerHTML = '<td class="empty" colspan="3">برچسبی ثبت نشده است.</td>';
        labelsBody.appendChild(row);
        return;
      }

      const sortedLabels = [...ASSETS_MANAGER_STATE.labels].sort((a, b) => {
        return labelPathName(a.id).localeCompare(labelPathName(b.id), "fa");
      });

      sortedLabels.forEach((label) => {
        const row = document.createElement("tr");
        row.dataset.id = String(label.id || "");

        const nameCell = document.createElement("td");
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.dataset.field = "name";
        nameInput.value = String(label.name || "");
        nameCell.appendChild(nameInput);

        const parentCell = document.createElement("td");
        const parentSelect = document.createElement("select");
        parentSelect.dataset.field = "parent_id";
        fillLabelParentOptions(parentSelect, String(label.parent_id || ""), String(label.id || ""));
        parentCell.appendChild(parentSelect);

        const actionCell = document.createElement("td");
        actionCell.className = "pm-actions-cell";
        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "btn ghost";
        removeButton.dataset.action = "remove-label";
        removeButton.textContent = "حذف";
        actionCell.appendChild(removeButton);

        row.append(nameCell, parentCell, actionCell);
        labelsBody.appendChild(row);
      });
    };

    const renderAll = () => {
      const selectedAncestorId = String(assetAncestorSelect?.value || "").trim();
      const selectedStorageId = String(assetStorageSelect?.value || "").trim();
      const selectedParentLabelId = String(labelParentSelect?.value || "").trim();
      const selectedChain = collectAncestorChainSelected();

      fillAncestorOptions(assetAncestorSelect, selectedAncestorId);
      fillStorageOptions(assetStorageSelect, selectedStorageId);
      fillLabelParentOptions(labelParentSelect, selectedParentLabelId);

      renderAssets();
      renderStorages();
      renderAncestors();
      renderLabels();

      renderAncestorLabelChain(selectedChain);
      setSpecialMode(Boolean(assetSpecialToggle?.checked));
    };

    const syncAssetsManager = async (action, payload = {}) => {
      const body = new FormData();
      body.append("action", action);
      Object.entries(payload).forEach(([key, value]) => {
        body.append(key, String(value ?? ""));
      });

      const response = await fetch(endpoint, {
        method: "POST",
        body,
        credentials: "same-origin"
      });

      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        throw new Error("پاسخ نامعتبر از سرویس مدیریت اموال دریافت شد.");
      }

      if (!response.ok || data?.status !== "ok") {
        throw new Error(data?.message || "در ارتباط با سرویس مدیریت اموال خطا رخ داد.");
      }

      ASSETS_MANAGER_STATE.storages = Array.isArray(data.storages) ? data.storages : [];
      ASSETS_MANAGER_STATE.labels = Array.isArray(data.labels) ? data.labels : [];
      ASSETS_MANAGER_STATE.ancestors = Array.isArray(data.ancestors) ? data.ancestors : [];
      ASSETS_MANAGER_STATE.assets = Array.isArray(data.assets) ? data.assets : [];

      renderAll();
      return data;
    };
    paneButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const targetPane = button.dataset.pmPaneTarget || "";
        if (!targetPane) return;
        setPane(targetPane);
      });
    });

    assetSpecialToggle?.addEventListener("change", () => {
      setSpecialMode(Boolean(assetSpecialToggle.checked));
    });

    assetAncestorSelect?.addEventListener("change", () => {
      renderAssetLabelFields();
    });

    ancestorLabelsModalCloseButtons.forEach((button) => {
      button.addEventListener("click", () => {
        closeAncestorLabelsModal();
      });
    });

    ancestorLabelsModal?.addEventListener("click", (event) => {
      if (event.target === ancestorLabelsModal) {
        closeAncestorLabelsModal();
      }
    });

    ancestorLabelsModalSave?.addEventListener("click", async () => {
      const ancestorId = String(editingAncestorLabelsId || "").trim();
      if (!ancestorId) {
        closeAncestorLabelsModal();
        return;
      }

      const labelIds = readLabelChainSelected(ancestorLabelsModalChain);
      ancestorLabelsModalSave.disabled = true;
      setStatus(ancestorLabelsModalStatus, "\u062F\u0631 \u062D\u0627\u0644 \u0630\u062E\u06CC\u0631\u0647...");
      try {
        await syncAssetsManager("update_ancestor", {
          id: ancestorId,
          field: "label_ids",
          value: JSON.stringify(labelIds)
        });
        setStatus(ancestorStatus, "\u0628\u0631\u0686\u0633\u0628\u200C\u0647\u0627\u06CC \u0645\u0627\u0644 \u0645\u0631\u0633\u0648\u0645 \u0630\u062E\u06CC\u0631\u0647 \u0634\u062F.");
        closeAncestorLabelsModal();
      } catch (error) {
        setStatus(ancestorLabelsModalStatus, error?.message || "\u0630\u062E\u06CC\u0631\u0647 \u0628\u0631\u0686\u0633\u0628\u200C\u0647\u0627 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F.", true);
      } finally {
        ancestorLabelsModalSave.disabled = false;
      }
    });

    assetLabelsModalCloseButtons.forEach((button) => {
      button.addEventListener("click", () => {
        closeAssetLabelsModal();
      });
    });

    assetLabelsModal?.addEventListener("click", (event) => {
      if (event.target === assetLabelsModal) {
        closeAssetLabelsModal();
      }
    });

    assetLabelsModalSave?.addEventListener("click", async () => {
      const assetId = String(editingAssetLabelsId || "").trim();
      if (!assetId) {
        closeAssetLabelsModal();
        return;
      }

      const labelValues = readAssetModalLabelValues();
      assetLabelsModalSave.disabled = true;
      setStatus(assetLabelsModalStatus, "\u062F\u0631 \u062D\u0627\u0644 \u0630\u062E\u06CC\u0631\u0647...");
      try {
        await syncAssetsManager("update_asset", {
          id: assetId,
          field: "label_values",
          value: JSON.stringify(labelValues)
        });
        setStatus(assetStatus, "\u0628\u0631\u0686\u0633\u0628\u200C\u0647\u0627\u06CC \u0645\u0627\u0644 \u0630\u062E\u06CC\u0631\u0647 \u0634\u062F.");
        closeAssetLabelsModal();
      } catch (error) {
        setStatus(assetLabelsModalStatus, error?.message || "\u0630\u062E\u06CC\u0631\u0647 \u0628\u0631\u0686\u0633\u0628\u200C\u0647\u0627 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F.", true);
      } finally {
        assetLabelsModalSave.disabled = false;
      }
    });

    storageForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const name = String(storageNameInput?.value || "").trim();
      if (!name) {
        setStatus(storageStatus, "نام انبار الزامی است.", true);
        return;
      }

      setStatus(storageStatus, "در حال ذخیره...");
      try {
        await syncAssetsManager("add_storage", { name });
        storageForm.reset();
        setStatus(storageStatus, "انبار با موفقیت اضافه شد.");
      } catch (error) {
        setStatus(storageStatus, error?.message || "افزودن انبار انجام نشد.", true);
      }
    });

    labelForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const name = String(labelNameInput?.value || "").trim();
      const parentId = String(labelParentSelect?.value || "").trim();
      if (!name) {
        setStatus(labelStatus, "نام برچسب الزامی است.", true);
        return;
      }

      setStatus(labelStatus, "در حال ذخیره...");
      try {
        await syncAssetsManager("add_label", {
          name,
          parent_id: parentId
        });
        labelForm.reset();
        fillLabelParentOptions(labelParentSelect, "");
        setStatus(labelStatus, "برچسب با موفقیت اضافه شد.");
      } catch (error) {
        setStatus(labelStatus, error?.message || "افزودن برچسب انجام نشد.", true);
      }
    });

    ancestorForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const name = String(ancestorNameInput?.value || "").trim();
      if (!name) {
        setStatus(ancestorStatus, "نام مال مرسوم الزامی است.", true);
        return;
      }

      const labelIds = collectAncestorChainSelected();
      setStatus(ancestorStatus, "در حال ذخیره...");
      try {
        await syncAssetsManager("add_ancestor", {
          name,
          label_ids: JSON.stringify(labelIds)
        });
        ancestorForm.reset();
        renderAncestorLabelChain([]);
        setStatus(ancestorStatus, "مال مرسوم با موفقیت اضافه شد.");
      } catch (error) {
        setStatus(ancestorStatus, error?.message || "افزودن مال مرسوم انجام نشد.", true);
      }
    });

    assetForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const specialAsset = Boolean(assetSpecialToggle?.checked);
      const ancestorId = String(assetAncestorSelect?.value || "").trim();
      const name = String(assetNameInput?.value || "").trim();
      const code = String(assetCodeInput?.value || "").trim();
      const storageId = String(assetStorageSelect?.value || "").trim();

      if (!code || !storageId || (!specialAsset && !ancestorId) || (specialAsset && !name)) {
        setStatus(assetStatus, "همه فیلدهای الزامی را تکمیل کنید.", true);
        return;
      }

      const labelValues = specialAsset ? {} : collectAssetLabelValues();
      setStatus(assetStatus, "در حال ذخیره...");
      try {
        await syncAssetsManager("add_asset", {
          special_asset: specialAsset ? "1" : "0",
          ancestor_id: specialAsset ? "" : ancestorId,
          name: specialAsset ? name : "",
          code,
          storage_id: storageId,
          label_values: JSON.stringify(labelValues)
        });
        assetForm.reset();
        if (assetSpecialToggle) {
          assetSpecialToggle.checked = false;
        }
        setSpecialMode(false);
        setStatus(assetStatus, "مال با موفقیت اضافه شد.");
      } catch (error) {
        setStatus(assetStatus, error?.message || "افزودن مال انجام نشد.", true);
      }
    });
    assetsBody?.addEventListener("change", async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return;
      const row = target.closest("tr");
      const id = String(row?.dataset.id || "");
      const field = String(target.dataset.field || "");
      const value = String(target.value || "").trim();
      if (!id || !field || !value) {
        renderAssets();
        return;
      }

      target.disabled = true;
      try {
        await syncAssetsManager("update_asset", { id, field, value });
      } catch (error) {
        renderAssets();
        setStatus(assetStatus, error?.message || "ذخیره تغییرات مال انجام نشد.", true);
      } finally {
        target.disabled = false;
      }
    });

    storagesBody?.addEventListener("change", async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) return;
      const row = target.closest("tr");
      const id = String(row?.dataset.id || "");
      const value = String(target.value || "").trim();
      if (!id || !value) {
        renderStorages();
        return;
      }

      target.disabled = true;
      try {
        await syncAssetsManager("update_storage", { id, value });
      } catch (error) {
        renderStorages();
        setStatus(storageStatus, error?.message || "ذخیره تغییرات انبار انجام نشد.", true);
      } finally {
        target.disabled = false;
      }
    });

    ancestorsBody?.addEventListener("change", async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) return;
      const row = target.closest("tr");
      const id = String(row?.dataset.id || "");
      const value = String(target.value || "").trim();
      if (!id || !value) {
        renderAncestors();
        return;
      }

      target.disabled = true;
      try {
        await syncAssetsManager("update_ancestor", { id, value });
      } catch (error) {
        renderAncestors();
        setStatus(ancestorStatus, error?.message || "ذخیره تغییرات مال مرسوم انجام نشد.", true);
      } finally {
        target.disabled = false;
      }
    });

    labelsBody?.addEventListener("change", async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return;
      const row = target.closest("tr");
      const id = String(row?.dataset.id || "");
      const field = String(target.dataset.field || "");
      const value = String(target.value || "").trim();
      if (!id || !field) {
        renderLabels();
        return;
      }
      if (field === "name" && !value) {
        renderLabels();
        return;
      }

      target.disabled = true;
      try {
        await syncAssetsManager("update_label", { id, field, value });
      } catch (error) {
        renderLabels();
        setStatus(labelStatus, error?.message || "ذخیره تغییرات برچسب انجام نشد.", true);
      } finally {
        target.disabled = false;
      }
    });

    assetsBody?.addEventListener("click", async (event) => {
      const editButton = event.target instanceof Element ? event.target.closest('[data-action="edit-asset-labels"]') : null;
      if (editButton instanceof HTMLButtonElement) {
        const id = String(editButton.closest("tr")?.dataset.id || "");
        if (!id) return;
        openAssetLabelsModal(id);
        return;
      }

      const button = event.target instanceof Element ? event.target.closest('[data-action="remove-asset"]') : null;
      if (!(button instanceof HTMLButtonElement)) return;
      const id = String(button.closest("tr")?.dataset.id || "");
      if (!id || !confirm("این مال حذف شود؟")) return;

      button.disabled = true;
      try {
        await syncAssetsManager("remove_asset", { id });
        setStatus(assetStatus, "مال حذف شد.");
      } catch (error) {
        setStatus(assetStatus, error?.message || "حذف مال انجام نشد.", true);
      } finally {
        button.disabled = false;
      }
    });

    storagesBody?.addEventListener("click", async (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-action="remove-storage"]') : null;
      if (!(button instanceof HTMLButtonElement)) return;
      const id = String(button.closest("tr")?.dataset.id || "");
      if (!id || !confirm("این انبار حذف شود؟")) return;

      button.disabled = true;
      try {
        await syncAssetsManager("remove_storage", { id });
        setStatus(storageStatus, "انبار حذف شد.");
      } catch (error) {
        setStatus(storageStatus, error?.message || "حذف انبار انجام نشد.", true);
      } finally {
        button.disabled = false;
      }
    });

    ancestorsBody?.addEventListener("click", async (event) => {
      const editButton = event.target instanceof Element ? event.target.closest('[data-action="edit-ancestor-labels"]') : null;
      if (editButton instanceof HTMLButtonElement) {
        const id = String(editButton.closest("tr")?.dataset.id || "");
        if (!id) return;
        openAncestorLabelsModal(id);
        return;
      }

      const button = event.target instanceof Element ? event.target.closest('[data-action="remove-ancestor"]') : null;
      if (!(button instanceof HTMLButtonElement)) return;
      const id = String(button.closest("tr")?.dataset.id || "");
      if (!id || !confirm("\u0627\u06CC\u0646 \u0645\u0627\u0644 \u0645\u0631\u0633\u0648\u0645 \u062D\u0630\u0641 \u0634\u0648\u062F\u061F")) return;

      button.disabled = true;
      try {
        await syncAssetsManager("remove_ancestor", { id });
        setStatus(ancestorStatus, "\u0645\u0627\u0644 \u0645\u0631\u0633\u0648\u0645 \u062D\u0630\u0641 \u0634\u062F.");
      } catch (error) {
        setStatus(ancestorStatus, error?.message || "\u062D\u0630\u0641 \u0645\u0627\u0644 \u0645\u0631\u0633\u0648\u0645 \u0627\u0646\u062C\u0627\u0645 \u0646\u0634\u062F.", true);
      } finally {
        button.disabled = false;
      }
    });

    labelsBody?.addEventListener("click", async (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-action="remove-label"]') : null;
      if (!(button instanceof HTMLButtonElement)) return;
      const id = String(button.closest("tr")?.dataset.id || "");
      if (!id || !confirm("این برچسب حذف شود؟")) return;

      button.disabled = true;
      try {
        await syncAssetsManager("remove_label", { id });
        setStatus(labelStatus, "برچسب حذف شد.");
      } catch (error) {
        setStatus(labelStatus, error?.message || "حذف برچسب انجام نشد.", true);
      } finally {
        button.disabled = false;
      }
    });

    setPane("assets");
    setSpecialMode(false);
    void syncAssetsManager("load_data").catch((error) => {
      setStatus(assetStatus, error?.message || "بارگذاری اطلاعات مدیریت اموال انجام نشد.", true);
    });
  }

  window.initAssetsManagerTab = initAssetsManagerTab;
})();
