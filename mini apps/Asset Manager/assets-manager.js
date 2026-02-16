(function () {
  let assetsManagerInitialized = false;
  const ASSETS_MANAGER_STATE = {
    storages: [],
    ancestors: [],
    assets: []
  };
  const DEFAULT_ENDPOINT = "mini%20apps/Asset%20Manager/index.php";

  const qs = (selector, root) => (root || document).querySelector(selector);
  const qsa = (selector, root) => Array.from((root || document).querySelectorAll(selector));

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
    const assetSpecialToggle = qs("#pm-asset-special", root);
    const assetAncestorField = qs("#pm-asset-ancestor-field", root);
    const assetNameField = qs("#pm-asset-name-field", root);
    const assetAncestorSelect = qs("#pm-asset-ancestor", root);
    const assetNameInput = qs("#pm-asset-name", root);
    const assetCodeInput = qs("#pm-asset-code", root);
    const assetStorageSelect = qs("#pm-asset-storage", root);
    const storageNameInput = qs("#pm-storage-name", root);
    const ancestorNameInput = qs("#pm-ancestor-name", root);
    const assetStatus = qs("#pm-asset-status", root);
    const storageStatus = qs("#pm-storage-status", root);
    const ancestorStatus = qs("#pm-ancestor-status", root);
    const assetsBody = qs("#pm-assets-body", root);
    const storagesBody = qs("#pm-storages-body", root);
    const ancestorsBody = qs("#pm-ancestors-body", root);

    const setStatus = (element, message, isError) => {
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
    };

    const fillStorageOptions = (select, selected) => {
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

    const fillAncestorOptions = (select, selected) => {
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

    const renderAll = () => {
      fillAncestorOptions(assetAncestorSelect, assetAncestorSelect?.value || "");
      fillStorageOptions(assetStorageSelect, assetStorageSelect?.value || "");
      setSpecialMode(Boolean(assetSpecialToggle?.checked));
      renderAssets();
      renderStorages();
      renderAncestors();
    };

    const syncAssetsManager = async (action, payload) => {
      const body = new FormData();
      body.append("action", action);
      Object.entries(payload || {}).forEach(([key, value]) => {
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

    ancestorForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const name = String(ancestorNameInput?.value || "").trim();
      if (!name) {
        setStatus(ancestorStatus, "نام مال مرسوم الزامی است.", true);
        return;
      }
      setStatus(ancestorStatus, "در حال ذخیره...");
      try {
        await syncAssetsManager("add_ancestor", { name });
        ancestorForm.reset();
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

      setStatus(assetStatus, "در حال ذخیره...");
      try {
        await syncAssetsManager("add_asset", {
          special_asset: specialAsset ? "1" : "0",
          ancestor_id: specialAsset ? "" : ancestorId,
          name: specialAsset ? name : "",
          code,
          storage_id: storageId
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

    setPane("assets");
    setSpecialMode(false);
    void syncAssetsManager("load_data").catch((error) => {
      setStatus(assetStatus, error?.message || "بارگذاری اطلاعات مدیریت اموال انجام نشد.", true);
    });
  }

  window.initAssetsManagerTab = initAssetsManagerTab;
})();
