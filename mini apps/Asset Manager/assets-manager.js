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

  const getRootLabelCandidates = () => {
    return ASSETS_MANAGER_STATE.labels.filter((label) => {
      const id = String(label?.id || "");
      const parentId = String(label?.parent_id || "");
      return parentId === "" || labelHasChildren(id);
    });
  };

  const getDescendantsAndSelf = (rootId) => {
    const root = String(rootId || "");
    if (!root) return [];

    const result = [];
    const queue = [root];
    const seen = new Set();

    while (queue.length) {
      const currentId = String(queue.shift() || "");
      if (!currentId || seen.has(currentId)) continue;
      seen.add(currentId);

      const label = byId(ASSETS_MANAGER_STATE.labels, currentId);
      if (!label) continue;
      result.push(label);

      getChildLabels(currentId).forEach((child) => {
        queue.push(String(child.id || ""));
      });
    }

    return result;
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

    const collectAncestorChainSelected = (maxDepth = null) => {
      if (!ancestorLabelChain) return [];
      const selected = [];
      qsa("select[data-chain-depth]", ancestorLabelChain).forEach((select) => {
        const depth = Number(select.dataset.chainDepth || "0");
        if (maxDepth !== null && depth > maxDepth) {
          return;
        }
        const value = String(select.value || "").trim();
        if (!value) {
          return;
        }
        selected.push(value);
      });
      return selected;
    };

    const renderAncestorLabelChain = (selectedIds = []) => {
      if (!ancestorLabelChain) return;
      ancestorLabelChain.innerHTML = "";

      const finalSelected = [];
      let depth = 0;
      let parentId = "";

      while (true) {
        const options = depth === 0 ? getRootLabelCandidates() : getChildLabels(parentId);
        if (!options.length) {
          if (depth === 0) {
            const hint = document.createElement("p");
            hint.className = "hint";
            hint.textContent = "برچسبی ثبت نشده است.";
            ancestorLabelChain.appendChild(hint);
          }
          break;
        }

        const wrapper = document.createElement("label");
        wrapper.className = "field";

        const caption = document.createElement("span");
        caption.textContent = `برچسب سطح ${depth + 1}`;

        const select = document.createElement("select");
        select.dataset.chainDepth = String(depth);

        const emptyOption = document.createElement("option");
        emptyOption.value = "";
        emptyOption.textContent = "انتخاب برچسب";
        select.appendChild(emptyOption);

        options.forEach((label) => {
          const option = document.createElement("option");
          option.value = String(label.id || "");
          option.textContent = String(label.name || "");
          select.appendChild(option);
        });

        const desired = String(selectedIds[depth] || "");
        if (desired && options.some((label) => String(label.id || "") === desired)) {
          select.value = desired;
        }

        wrapper.append(caption, select);
        ancestorLabelChain.appendChild(wrapper);

        select.addEventListener("change", () => {
          const currentDepth = Number(select.dataset.chainDepth || "0");
          const nextSelected = collectAncestorChainSelected(currentDepth);
          renderAncestorLabelChain(nextSelected);
        });

        const picked = String(select.value || "").trim();
        if (!picked) {
          break;
        }

        finalSelected.push(picked);
        parentId = picked;
        depth += 1;
      }

      ancestorLabelChain.dataset.selected = JSON.stringify(finalSelected);
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

      labelIds.forEach((labelId, index) => {
        const sourceLabel = byId(ASSETS_MANAGER_STATE.labels, labelId);
        const options = getDescendantsAndSelf(labelId);

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
      });

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
    const renderAssets = () => {
      if (!assetsBody) return;
      assetsBody.innerHTML = "";
      if (!ASSETS_MANAGER_STATE.assets.length) {
        const row = document.createElement("tr");
        row.innerHTML = '<td class="empty" colspan="4">مالی ثبت نشده است.</td>';
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

        const actionCell = document.createElement("td");
        actionCell.className = "pm-actions-cell";
        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "btn ghost";
        removeButton.dataset.action = "remove-asset";
        removeButton.textContent = "حذف";
        actionCell.appendChild(removeButton);

        row.append(titleCell, codeCell, storageCell, actionCell);
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
        row.innerHTML = '<td class="empty" colspan="2">مال مرسومی ثبت نشده است.</td>';
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

        const actionCell = document.createElement("td");
        actionCell.className = "pm-actions-cell";
        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "btn ghost";
        removeButton.dataset.action = "remove-ancestor";
        removeButton.textContent = "حذف";
        actionCell.appendChild(removeButton);

        row.append(nameCell, actionCell);
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
      const button = event.target instanceof Element ? event.target.closest('[data-action="remove-ancestor"]') : null;
      if (!(button instanceof HTMLButtonElement)) return;
      const id = String(button.closest("tr")?.dataset.id || "");
      if (!id || !confirm("این مال مرسوم حذف شود؟")) return;

      button.disabled = true;
      try {
        await syncAssetsManager("remove_ancestor", { id });
        setStatus(ancestorStatus, "مال مرسوم حذف شد.");
      } catch (error) {
        setStatus(ancestorStatus, error?.message || "حذف مال مرسوم انجام نشد.", true);
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