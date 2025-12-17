(function applyGlobalAppearance() {
  const defaults = {
    primary: "#e11d2e",
    background: "#ffffff",
    text: "#111111",
    toggle: "#e11d2e"
  };
  const storageKeys = {
    primary: "frontend_appearance_primary",
    background: "frontend_appearance_background",
    text: "frontend_appearance_text",
    toggle: "frontend_appearance_toggle"
  };

  const isHex = (value = "") => /^#(?:[0-9a-fA-F]{6})$/.test(value.trim());
  const normalizeHex = (value = "") => {
    if (typeof value !== "string") {
      return "";
    }
    const trimmed = value.trim();
    if (/^#([0-9a-fA-F]{3}){1,2}$/.test(trimmed)) {
      if (trimmed.length === 4) {
        const expanded = `#${trimmed[1]}${trimmed[1]}${trimmed[2]}${trimmed[2]}${trimmed[3]}${trimmed[3]}`;
        return expanded.toLowerCase();
      }
      return trimmed.toLowerCase();
    }
    if (isHex(trimmed)) {
      return trimmed.toLowerCase();
    }
    return "";
  };

  const getStoredColor = key => {
    try {
      const raw = window.localStorage.getItem(key) || "";
      return normalizeHex(raw);
    } catch {
      return "";
    }
  };

  const storedPalette = {};
  Object.keys(storageKeys).forEach(key => {
    storedPalette[key] = getStoredColor(storageKeys[key]);
  });

  const sharedAppearance =
    window.GENERAL_SETTINGS &&
    typeof window.GENERAL_SETTINGS === "object" &&
    typeof window.GENERAL_SETTINGS.appearance === "object"
      ? window.GENERAL_SETTINGS.appearance
      : {};

  const palette = Object.keys(defaults).reduce((acc, key) => {
    const serverColor = normalizeHex(sharedAppearance?.[key] ?? "");
    acc[key] = serverColor || storedPalette[key] || defaults[key];
    return acc;
  }, {});

  const hexToRgb = hex => {
    const normalized = normalizeHex(hex);
    if (!normalized) {
      return null;
    }
    return {
      r: parseInt(normalized.slice(1, 3), 16),
      g: parseInt(normalized.slice(3, 5), 16),
      b: parseInt(normalized.slice(5, 7), 16)
    };
  };

  const rgbToHsl = (r, g, b) => {
    r /= 255;
    g /= 255;
    b /= 255;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const delta = max - min;
    let h = 0;
    if (delta !== 0) {
      if (max === r) {
        h = ((g - b) / delta) % 6;
      } else if (max === g) {
        h = (b - r) / delta + 2;
      } else {
        h = (r - g) / delta + 4;
      }
      h *= 60;
      if (h < 0) {
        h += 360;
      }
    }
    const l = (max + min) / 2;
    const s = delta === 0 ? 0 : delta / (1 - Math.abs(2 * l - 1));
    return { h, s, l };
  };

  const hslToHex = ({ h, s, l }) => {
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = l - c / 2;
    let r = 0;
    let g = 0;
    let b = 0;
    if (h >= 0 && h < 60) {
      r = c;
      g = x;
    } else if (h < 120) {
      r = x;
      g = c;
    } else if (h < 180) {
      g = c;
      b = x;
    } else if (h < 240) {
      g = x;
      b = c;
    } else if (h < 300) {
      r = x;
      b = c;
    } else {
      r = c;
      b = x;
    }
    const toHex = value => {
      const channel = Math.round((value + m) * 255);
      return channel.toString(16).padStart(2, "0");
    };
    return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
  };

  const adjustHexLightness = (hex, delta) => {
    const rgb = hexToRgb(hex);
    if (!rgb) {
      return "";
    }
    const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
    hsl.l = Math.max(0, Math.min(1, hsl.l + delta));
    return hslToHex(hsl);
  };

  const hexToRgba = (hex, alpha) => {
    const rgb = hexToRgb(hex);
    if (!rgb) {
      return "";
    }
    return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
  };

  const root = document.documentElement;
  root.style.setProperty("--primary", palette.primary);
  root.style.setProperty(
    "--primary-600",
    adjustHexLightness(palette.primary, -0.18) || palette.primary
  );
  root.style.setProperty(
    "--primary-focus",
    hexToRgba(palette.primary, 0.12) || "rgba(225, 29, 46, 0.12)"
  );
  root.style.setProperty("--bg", palette.background);
  root.style.setProperty("--text", palette.text);
  const toggleBg = hexToRgba(palette.toggle, 0.12) || "rgba(225, 29, 46, 0.08)";
  const toggleBorder =
    hexToRgba(palette.toggle, 0.22) || "rgba(225, 29, 46, 0.22)";
  root.style.setProperty("--sidebar-active", toggleBg);
  root.style.setProperty("--sidebar-active-border", toggleBorder);
})();
