/* assets/register.js */
(function () {
  "use strict";

  const apiFetch = window.wp?.apiFetch;
  if (!apiFetch || !window.JC_POS?.nonce) return;

  apiFetch.use(apiFetch.createNonceMiddleware(JC_POS.nonce));

  // ---------------- DOM helpers ----------------
  const byId = (id) => document.getElementById(id);

  // Main UI
  const elMenus = byId("jc-menus");
  const elProducts = byId("jc-products");
  const elCart = byId("jc-cart");
  const elSubtotal = byId("jc-subtotal");
  const elTotal = byId("jc-total");
  const elResult = byId("jc-result");

  const elSearch = byId("jc-search");
  const elRefresh = byId("jc-refresh");
  const elCheckout = byId("jc-checkout");

  const elDiscountType = byId("jc-discount-type");
  const elDiscountValue = byId("jc-discount-value");
  const elFeeLabel = byId("jc-fee-label");
  const elFeeValue = byId("jc-fee-value");

  // Cash/change (optional – only if your page has these)
  const elCashReceived = byId("jc-cash-received");
  const elCashExact = byId("jc-cash-exact");
  const elChangeDue = byId("jc-change-due");

  // Product modal
  const elModal = byId("jc-modal");
  const elModalTitle = byId("jc-modal-title");
  const elSize = byId("jc-opt-size");
  const elFlavor = byId("jc-opt-flavor");
  const elToppings = byId("jc-opt-toppings");
  const elItemTotal = byId("jc-item-total");
  const elModalClose = byId("jc-modal-close");
  const elAddToCart = byId("jc-add-to-cart");

  // Receipt modal
  const elReceiptModal = byId("jc-receipt-modal");
  const elReceiptBody = byId("jc-receipt-body");
  const elReceiptCloseX = byId("jc-receipt-close");
  const elReceiptPrint = byId("jc-receipt-print");
  const elReceiptNewSale = byId("jc-receipt-new-sale");
  const elReceiptCloseBtn = byId("jc-receipt-close-btn");

  // MH box inside receipt modal
  const elMhBox = byId("jc-mh-box");
  const elMhPill = byId("jc-mh-pill");
  const elMhSummary = byId("jc-mh-summary");
  const elMhDetails = byId("jc-mh-details");
  const elMhJson = byId("jc-mh-json");
  const elQrWrap = byId("jc-mh-qr-wrap");
  const elQr = byId("jc-mh-qr");

  // Document type
  const elDocRadios = document.querySelectorAll('input[name="jc_doc_type"]');
  const elDocBadge = byId("jc-doc-badge");

  // Customer picker
  const elCustomerBox = byId("jc-register-customer-box");
  const elCustomerJson = byId("jc-pos-customer-index");
  const elCustomerSearch = byId("jc-customer-search");
  const elCustomerSearchBtn = byId("jc-customer-search-btn");
  const elCustomerResults = byId("jc-customer-results");
  const elCustomerError = byId("jc-customer-error");
  const elCustomerRequirement = byId("jc-customer-requirement-text");
  const elClearCustomer = byId("jc-clear-customer");

  // Hidden customer fields
  const elSaleCustomerId = byId("jc-sale-customer-id");
  const elSaleCustomerName = byId("jc-sale-customer-name");
  const elSaleCustomerCompany = byId("jc-sale-customer-company");
  const elSaleCustomerNrc = byId("jc-sale-customer-nrc");
  const elSaleCustomerNit = byId("jc-sale-customer-nit");
  const elSaleCustomerAddress = byId("jc-sale-customer-address");
  const elSaleCustomerCity = byId("jc-sale-customer-city");
  const elSaleCustomerPhone = byId("jc-sale-customer-phone");
  const elSaleCustomerEmail = byId("jc-sale-customer-email");

  // ---------------- State ----------------
  let MENUS = [];
  let SIZES = [];
  let TOPPINGS = [];
  let SYRUPS = [];
  let PRODUCTS = [];
  let CART = [];
  let currentMenuId = null;
  let currentProduct = null;
  let CUSTOMER_INDEX = [];

  // ---------------- Utils ----------------
  function money(n) {
    const x = Number(n || 0);
    return "$" + (Math.round(x * 100) / 100).toFixed(2);
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function normalizeText(value) {
    return String(value || "").trim().toLowerCase();
  }

  function parseNum(v) {
    const n = parseFloat(String(v ?? "").trim());
    return Number.isFinite(n) ? n : 0;
  }

  function apiGet(path) {
    return apiFetch({ path: "/jc-pos/v1" + path });
  }

  // ---------------- Totals + change ----------------
  function feeAmount() {
    return Math.max(0, parseFloat(elFeeValue?.value || "0") || 0);
  }

  function cartSubtotal() {
    return CART.reduce((s, l) => s + Number(l.unit_price || 0) * Number(l.qty || 1), 0);
  }

  function applyDiscount(subtotal) {
    const t = elDiscountType?.value || "none";
    const v = parseFloat(elDiscountValue?.value || "0") || 0;
    if (t === "percent") return Math.max(0, subtotal - subtotal * (v / 100));
    if (t === "amount") return Math.max(0, subtotal - v);
    return subtotal;
  }

  function cartTotal() {
    const sub = cartSubtotal();
    const afterDiscount = applyDiscount(sub);
    return Math.round((afterDiscount + feeAmount()) * 100) / 100;
  }

  function updateChangeUI() {
    if (!elCashReceived || !elChangeDue) return;

    const total = cartTotal();
    const cash = parseNum(elCashReceived.value);
    const change = Math.max(0, Math.round((cash - total) * 100) / 100);

    elChangeDue.textContent = money(change);
    elChangeDue.style.color = cash > 0 && cash < total ? "#d63638" : "";
  }

  function updateTotalsUI() {
    const sub = cartSubtotal();
    const tot = cartTotal();
    if (elSubtotal) elSubtotal.textContent = money(sub);
    if (elTotal) elTotal.textContent = money(tot);
    updateChangeUI();
  }

  // ---------------- Document type ----------------
  function getDocType() {
    const checked = document.querySelector('input[name="jc_doc_type"]:checked');
    const v = (checked?.value || "CONSUMIDOR_FINAL").toUpperCase();
    return v === "CREDITO_FISCAL" ? "CREDITO_FISCAL" : "CONSUMIDOR_FINAL";
  }

  function hasSelectedCustomer() {
    return !!(elSaleCustomerId && String(elSaleCustomerId.value || "").trim() !== "");
  }

  function updateCustomerRequirementState() {
    if (!elCustomerRequirement) return;

    if (getDocType() !== "CREDITO_FISCAL") {
      elCustomerRequirement.textContent = "Optional for Consumidor Final. Required for Crédito Fiscal.";
      elCustomerRequirement.style.color = "#50575e";
      return;
    }

    if (hasSelectedCustomer()) {
      elCustomerRequirement.textContent = "Customer selected for Crédito Fiscal.";
      elCustomerRequirement.style.color = "#008a20";
    } else {
      elCustomerRequirement.textContent = "Crédito Fiscal requires a selected customer before completing the sale.";
      elCustomerRequirement.style.color = "#d63638";
    }
  }

  function updateDocUI() {
    const dt = getDocType();
    if (elDocBadge) elDocBadge.textContent = dt === "CREDITO_FISCAL" ? "Requiere datos fiscales" : "Venta rápida";
    updateCustomerRequirementState();
  }

  elDocRadios?.forEach((r) => r.addEventListener("change", updateDocUI));

  // Cash events (optional)
  elCashReceived?.addEventListener("input", updateChangeUI);
  elCashExact?.addEventListener("click", () => {
    if (!elCashReceived) return;
    elCashReceived.value = String(cartTotal());
    updateChangeUI();
  });

  // ---------------- Addons helpers ----------------
  function normalizeAddons(addons) {
    if (!addons) return [];
    if (!Array.isArray(addons) && typeof addons === "object") {
      const flat = [];
      Object.keys(addons).forEach((k) => {
        if (Array.isArray(addons[k])) {
          addons[k].forEach((a) => flat.push({ ...a, addon_type: a.addon_type || a.type || k }));
        }
      });
      return flat;
    }
    if (Array.isArray(addons)) return addons;
    return [];
  }

  function pickByType(allAddons, typeName) {
    const want = String(typeName).toUpperCase();
    return allAddons.filter((a) => String(a.addon_type || a.type || a.group || "").toUpperCase() === want);
  }

  // ---------------- Receipt modal + MH UI + QR ----------------
  function openReceiptModal(html) {
    if (!elReceiptModal || !elReceiptBody) return;
    elReceiptBody.innerHTML = html;
    elReceiptModal.classList.remove("jc-hidden");
    elReceiptModal.setAttribute("aria-hidden", "false");
  }

  function closeReceiptModal() {
    if (!elReceiptModal) return;
    elReceiptModal.classList.add("jc-hidden");
    elReceiptModal.setAttribute("aria-hidden", "true");
  }

  function clearReceiptModalUI() {
    if (elReceiptBody) elReceiptBody.innerHTML = "";

    if (elMhPill) {
      elMhPill.textContent = "";
      elMhPill.style.background = "";
      elMhPill.style.color = "";
    }
    if (elMhSummary) elMhSummary.textContent = "";
    if (elMhJson) elMhJson.textContent = "";
    if (elMhDetails) elMhDetails.open = false;

    if (elQr) elQr.innerHTML = "";
    if (elQrWrap) elQrWrap.style.display = "none";
  }

  function closeReceiptAndClear() {
    clearReceiptModalUI();
    closeReceiptModal();
  }

  elReceiptCloseX?.addEventListener("click", closeReceiptAndClear);
  elReceiptCloseBtn?.addEventListener("click", closeReceiptAndClear);

  function todayISOInElSalvador() {
    // sv-SE => YYYY-MM-DD
    return new Intl.DateTimeFormat("sv-SE", {
      timeZone: "America/El_Salvador",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    }).format(new Date());
  }

  // Official MH public verification URL for QR
  function buildMhQrUrl(res) {
    const mh = res?.mh || {};
    const dte = res?.dte || res?.dte_json || null;

    const ambiente = String(dte?.identificacion?.ambiente || JC_POS?.mh_ambiente || "00").trim() || "00";
    const codGen = String(dte?.identificacion?.codigoGeneracion || mh?.codigoGeneracion || "").trim();

    // If DTE isn't returned by your API, use "today" at moment of sale (SV timezone)
    const fechaEmi = String(dte?.identificacion?.fecEmi || "").trim() || todayISOInElSalvador();

    if (!codGen) return "";

    const base = "https://admin.factura.gob.sv/consultaPublica";
    return `${base}?ambiente=${encodeURIComponent(ambiente)}&codGen=${encodeURIComponent(codGen)}&fechaEmi=${encodeURIComponent(fechaEmi)}`;
  }

  function setMhUI(res) {
    const mh = res?.mh || {};
    if (!elMhBox) return;

    const estado = String(mh?.estado || "").toUpperCase();
    const codigo = String(mh?.codigoMsg || "");
    const desc = String(mh?.descripcionMsg || mh?.error || "");

    // pill
    if (elMhPill) {
      let bg = "#f0f0f1";
      let color = "#1d2327";
      if (estado === "PROCESADO") { bg = "#d4edda"; color = "#145a32"; }
      if (estado === "RECHAZADO") { bg = "#f8d7da"; color = "#842029"; }
      if (estado === "FAILED" || estado === "") { bg = "#fff3cd"; color = "#664d03"; }

      elMhPill.style.background = bg;
      elMhPill.style.color = color;
      elMhPill.textContent = estado || (mh?.mh_status ? String(mh.mh_status) : "—");
    }

    // summary
    if (elMhSummary) {
      const parts = [];
      if (codigo) parts.push(`Code: ${codigo}`);
      if (desc) parts.push(desc);
      elMhSummary.textContent = parts.length ? parts.join(" — ") : "—";
    }

    // QR
    const qrUrl = buildMhQrUrl(res);
    if (elQrWrap && elQr) {
      elQr.innerHTML = "";

      if (estado === "PROCESADO" && qrUrl) {
        elQrWrap.style.display = "block";

        if (window.QRCode) {
          new window.QRCode(elQr, { text: qrUrl, width: 160, height: 160 });
        } else {
          // If the QR lib isn't loaded, show URL as fallback so you know it's working
          elQr.innerHTML = `<div style="font-size:12px;color:#50575e;word-break:break-all;">${escapeHtml(qrUrl)}</div>`;
        }
      } else {
        elQrWrap.style.display = "none";
      }
    }

    // debug json
    if (elMhJson) elMhJson.textContent = JSON.stringify(mh || {}, null, 2);

    if (elMhDetails) elMhDetails.open = (estado === "RECHAZADO" || estado === "FAILED");
  }

  function printReceipt(html) {
    const w = window.open("", "jc_receipt", "width=380,height=650");
    if (!w) return;

    w.document.open();
    w.document.write(`
      <html>
        <head>
          <title>Receipt</title>
          <style>
            body{ margin:0; padding:12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12px; }
            hr{ border:0; border-top:1px dashed #999; margin:10px 0; }
            table{ width:100%; border-collapse:collapse; }
            td{ padding:4px 0; vertical-align:top; }
          </style>
        </head>
        <body>
          ${html}
          <script>
            window.onload = function(){ window.print(); window.close(); }
          </script>
        </body>
      </html>
    `);
    w.document.close();
  }

  // Grab QR image from DOM (qrcodejs renders canvas/img)
  function getQrDataUrl() {
    if (!elQr) return "";

    const img = elQr.querySelector("img");
    if (img && img.src && img.src.startsWith("data:")) return img.src;

    const canvas = elQr.querySelector("canvas");
    if (canvas && typeof canvas.toDataURL === "function") {
      try { return canvas.toDataURL("image/png"); } catch (_) { return ""; }
    }
    return "";
  }

  function injectQrIntoReceiptHtml(receiptHtml, qrDataUrl, qrUrlText) {
    if (!qrDataUrl && !qrUrlText) return receiptHtml;

    const qrBlock = qrDataUrl
      ? `
        <div style="margin-top:12px; display:flex; justify-content:center;">
          <div style="text-align:center;">
            <div style="font-size:11px; margin-bottom:6px;">QR MH</div>
            <img src="${qrDataUrl}" style="width:160px;height:160px;" />
          </div>
        </div>
      `
      : `
        <div style="margin-top:12px; font-size:11px; word-break:break-all;">
          QR MH: ${escapeHtml(qrUrlText)}
        </div>
      `;

    if (receiptHtml.includes("Gracias por su compra")) {
      return receiptHtml.replace("Gracias por su compra", `${qrBlock}Gracias por su compra`);
    }
    return receiptHtml + qrBlock;
  }

  function printReceiptWithQr(receiptHtml, res) {
    const qrUrlText = buildMhQrUrl(res);

    const tryPrint = () => {
      const qrDataUrl = getQrDataUrl();
      const printable = injectQrIntoReceiptHtml(receiptHtml, qrDataUrl, qrUrlText);
      printReceipt(printable);
    };

    // If QR library renders async, wait a tick
    if (getQrDataUrl()) return tryPrint();
    setTimeout(tryPrint, 60);
  }

  // ---------------- Receipt HTML ----------------
  function buildReceiptHtml(res, soldCart) {
    const dte = res?.dte || res?.dte_json || null;

    if (dte?.emisor && dte?.identificacion) {
      const em = dte.emisor;
      const id = dte.identificacion;
      const rc = dte.receptor || {};
      const dir = em.direccion?.complemento ? escapeHtml(em.direccion.complemento) : "";

      const lines = Array.isArray(dte.cuerpoDocumento) ? dte.cuerpoDocumento : [];
      const rows = lines.length
        ? lines.map((l) => {
            const qty = l.cantidad ?? 1;
            const desc = escapeHtml(l.descripcion ?? "");
            const price = Number(l.precioUni ?? 0);
            return `<tr><td>${qty}x ${desc}</td><td style="text-align:right">${money(price)}</td></tr>`;
          }).join("")
        : `<tr><td colspan="2"><em>No items</em></td></tr>`;

      const totalPagar = Number(dte.resumen?.totalPagar ?? 0);
      const totalLetras = escapeHtml(dte.resumen?.totalLetras ?? "");

      return `
        <div>
          <div style="font-size:14px;"><strong>${escapeHtml(em.nombreComercial || em.nombre || "TEAVO")}</strong></div>
          <div>${escapeHtml(em.descActividad || "")}</div>
          <div>NIT: ${escapeHtml(em.nit || "")} &nbsp; NRC: ${escapeHtml(em.nrc || "")}</div>
          <div>${dir}</div>
          <div>Tel: ${escapeHtml(em.telefono || "")}</div>
          <hr/>
          <div><strong>Control:</strong> ${escapeHtml(id.numeroControl || "")}</div>
          <div><strong>Generación:</strong> ${escapeHtml(id.codigoGeneracion || "")}</div>
          <div><strong>Fecha:</strong> ${escapeHtml(id.fecEmi || "")} ${escapeHtml(id.horEmi || "")}</div>
          <hr/>
          <div><strong>Cliente:</strong> ${escapeHtml(rc.nombre || "CONSUMIDOR FINAL")}</div>
          <hr/>
          <table>${rows}</table>
          <hr/>
          <div style="display:flex;justify-content:space-between;">
            <strong>Total</strong><strong>${money(totalPagar)}</strong>
          </div>
          <div style="margin-top:6px">${totalLetras}</div>
          <div style="margin-top:10px;text-align:center;">Gracias por su compra</div>
        </div>
      `;
    }

    // fallback (no DTE returned)
    const rows = (soldCart || []).length
      ? soldCart.map((l) => {
          const qty = Number(l.qty || 1);
          const name = escapeHtml(l.name || "");
          const lineTotal = Number(l.unit_price || 0) * qty;
          return `<tr><td>${qty}x ${name}</td><td style="text-align:right">${money(lineTotal)}</td></tr>`;
        }).join("")
      : `<tr><td colspan="2"><em>No items</em></td></tr>`;

    const sub = soldCart.reduce((s, l) => s + Number(l.unit_price || 0) * Number(l.qty || 1), 0);
    const afterDiscount = applyDiscount(sub);
    const tot = afterDiscount + feeAmount();

    return `
      <div>
        <div style="font-size:14px;"><strong>TEAVO</strong></div>
        <hr/>
        <table>${rows}</table>
        <hr/>
        <div style="display:flex;justify-content:space-between;">
          <strong>Total</strong><strong>${money(tot)}</strong>
        </div>
        <div style="margin-top:10px;text-align:center;">Gracias por su compra</div>
      </div>
    `;
  }

  // ---------------- UI rendering ----------------
  function renderMenus() {
    if (!elMenus) return;
    elMenus.innerHTML = "";

    MENUS.forEach((m) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "button jc-menu-btn" + (m.id === currentMenuId ? " button-primary" : "");
      btn.textContent = m.name;

      btn.addEventListener("click", async () => {
        currentMenuId = m.id;
        renderMenus();
        await loadMenuProducts(m.id);
      });

      elMenus.appendChild(btn);
    });
  }

  function renderProducts(filterText = "") {
    if (!elProducts) return;

    const q = (filterText || "").trim().toLowerCase();
    const list = q ? PRODUCTS.filter((p) => p.name.toLowerCase().includes(q)) : PRODUCTS;

    elProducts.innerHTML = "";
    if (!list.length) {
      elProducts.innerHTML = "<p><em>No products found.</em></p>";
      return;
    }

    list.forEach((p) => {
      const card = document.createElement("div");
      card.className = "jc-card";

      const img = document.createElement("div");
      img.className = "jc-img";
      img.style.backgroundImage = p.image ? `url(${p.image})` : "none";

      const name = document.createElement("div");
      name.className = "jc-name";
      name.textContent = p.name;

      const bottom = document.createElement("div");
      bottom.className = "jc-bottom";
      bottom.innerHTML = `<span>${money(p.price)}</span> <button class="button button-small" type="button">🛒</button>`;
      bottom.querySelector("button")?.addEventListener("click", () => openModal(p));

      card.appendChild(img);
      card.appendChild(name);
      card.appendChild(bottom);
      elProducts.appendChild(card);
    });
  }

  function renderCart() {
    if (!elCart) return;
    elCart.innerHTML = "";

    if (!CART.length) {
      elCart.innerHTML = "<p><em>Cart is empty</em></p>";
      updateTotalsUI();
      return;
    }

    CART.forEach((l, idx) => {
      const row = document.createElement("div");
      row.className = "jc-cart-row";

      const meta = [];
      if (l.meta?.size) meta.push(`Size: ${l.meta.size}`);
      if (l.meta?.syrup) meta.push(`Flavor: ${l.meta.syrup}`);
      if (l.meta?.toppings?.length) meta.push(`Toppings: ${l.meta.toppings.map((t) => t.name).join(", ")}`);

      row.innerHTML = `
        <div class="jc-cart-left">
          <strong>${escapeHtml(l.name)}</strong><br>
          <small>${escapeHtml(meta.join(" | "))}</small>
        </div>
        <div class="jc-cart-right">
          <input type="number" min="0.25" step="0.25" value="${Number(l.qty || 1)}" class="jc-qty">
          <div class="jc-lineprice">${money(l.unit_price)}</div>
          <button class="button button-small jc-remove" type="button">X</button>
        </div>
      `;

      row.querySelector(".jc-remove")?.addEventListener("click", () => {
        CART.splice(idx, 1);
        renderCart();
      });

      row.querySelector(".jc-qty")?.addEventListener("change", (e) => {
        l.qty = parseFloat(e.target.value || "1") || 1;
        renderCart();
      });

      elCart.appendChild(row);
    });

    updateTotalsUI();
  }

  // ---------------- Data loading ----------------
  async function loadBootstrap() {
    if (elResult) elResult.innerHTML = "";
    if (elProducts) elProducts.innerHTML = "<p>Loading...</p>";
    if (elMenus) elMenus.innerHTML = "";

    let boot;
    try {
      boot = await apiGet("/menu");
    } catch (e) {
      console.error(e);
      if (elProducts) elProducts.innerHTML = "<p>Failed to load POS menu.</p>";
      return;
    }

    MENUS = (boot.menus || []).map((m) => ({ id: parseInt(m.id, 10), name: m.name }));
    SIZES = (boot.sizes || []).map((s) => ({
      id: parseInt(s.id, 10),
      label: s.label,
      price_delta: parseFloat(s.price_delta || "0"),
      is_default: parseInt(s.is_default || "0", 10),
    }));

    const allAddons = normalizeAddons(boot.addons);
    TOPPINGS = pickByType(allAddons, "TOPPING").map((a) => ({
      id: String(a.id),
      name: a.name,
      price: parseFloat(a.price || "0"),
    }));
    SYRUPS = pickByType(allAddons, "SYRUP").map((a) => ({
      id: String(a.id),
      name: a.name,
      price: parseFloat(a.price || "0"),
    }));

    if (!MENUS.length) {
      if (elProducts) elProducts.innerHTML = "<p><em>No menus configured yet.</em></p>";
      return;
    }

    currentMenuId = MENUS[0].id;
    renderMenus();
    await loadMenuProducts(currentMenuId);
  }

  async function loadMenuProducts(menuId) {
    if (elProducts) elProducts.innerHTML = "<p>Loading products...</p>";

    let data;
    try {
      data = await apiGet(`/menu/${menuId}`);
    } catch (e) {
      console.error(e);
      if (elProducts) elProducts.innerHTML = "<p>Failed to load menu products.</p>";
      return;
    }

    PRODUCTS = (data.products || []).map((p) => ({
      id: parseInt(p.id, 10),
      name: p.name,
      price: parseFloat(p.price || "0"),
      image: p.image || null,
    }));

    renderProducts(elSearch?.value || "");
  }

  // ---------------- Product modal ----------------
  function getSelectedToppings() {
    const picks = [];
    elToppings?.querySelectorAll("input[type=checkbox]")?.forEach((cb) => {
      if (!cb.checked) return;
      const t = TOPPINGS.find((x) => x.id === cb.dataset.id);
      if (t) picks.push(t);
    });
    return picks;
  }

  function updateItemTotal() {
    if (!currentProduct) return;

    const sizeOpt = elSize?.options?.[elSize.selectedIndex];
    const basePrice = parseFloat(sizeOpt?.dataset.price || currentProduct.price || "0");

    const syrupOpt = elFlavor?.options?.[elFlavor.selectedIndex];
    const syrupExtra = parseFloat(syrupOpt?.dataset.price || "0");

    const tops = getSelectedToppings().reduce((s, t) => s + (t.price || 0), 0);
    if (elItemTotal) elItemTotal.textContent = money(basePrice + syrupExtra + tops);
  }

  function openModal(product) {
    currentProduct = product;
    if (elModalTitle) elModalTitle.textContent = product.name;

    // Syrups
    if (elFlavor) {
      elFlavor.innerHTML = "";
      const optDefault = document.createElement("option");
      optDefault.value = "";
      optDefault.dataset.price = "0";
      optDefault.textContent = "Default";
      elFlavor.appendChild(optDefault);

      SYRUPS.forEach((f) => {
        const opt = document.createElement("option");
        opt.value = f.id;
        opt.dataset.price = String(f.price || 0);
        opt.textContent = `${f.name} (+${money(f.price)})`;
        elFlavor.appendChild(opt);
      });
    }

    // Sizes
    if (elSize) {
      elSize.innerHTML = "";
      const base = parseFloat(product.price || "0");

      if (SIZES.length) {
        SIZES.forEach((s) => {
          const delta = parseFloat(s.price_delta || "0");
          const finalPrice = Math.round((base + delta) * 100) / 100;

          const opt = document.createElement("option");
          opt.value = String(s.id);
          opt.dataset.price = String(finalPrice);
          opt.textContent = `${s.label} (${money(finalPrice)})`;
          if (s.is_default === 1) opt.selected = true;

          elSize.appendChild(opt);
        });
      } else {
        const opt = document.createElement("option");
        opt.value = "";
        opt.dataset.price = String(base);
        opt.textContent = `Default (${money(base)})`;
        elSize.appendChild(opt);
      }
    }

    // Toppings
    if (elToppings) {
      elToppings.innerHTML = "";
      TOPPINGS.forEach((t) => {
        const row = document.createElement("label");
        row.className = "jc-top-row";
        row.innerHTML = `<input type="checkbox" data-id="${t.id}"> ${t.name} (+${money(t.price)})`;
        elToppings.appendChild(row);
      });
    }

    updateItemTotal();
    elModal?.classList.remove("jc-hidden");
    elModal?.setAttribute("aria-hidden", "false");
  }

  function closeModal() {
    elModal?.classList.add("jc-hidden");
    elModal?.setAttribute("aria-hidden", "true");
    currentProduct = null;
  }

  elModalClose?.addEventListener("click", closeModal);
  elSize?.addEventListener("change", updateItemTotal);
  elFlavor?.addEventListener("change", updateItemTotal);
  elToppings?.addEventListener("change", updateItemTotal);

  elAddToCart?.addEventListener("click", () => {
    if (!currentProduct) return;

    const sizeOpt = elSize?.options?.[elSize.selectedIndex];
    const sizePrice = parseFloat(sizeOpt?.dataset.price || currentProduct.price || "0");
    const sizeLabel = sizeOpt?.textContent ? sizeOpt.textContent.split(" (")[0] : "Default";

    const syrupOpt = elFlavor?.options?.[elFlavor.selectedIndex];
    const syrupLabel = syrupOpt?.value ? syrupOpt.textContent.split(" (")[0] : "";
    const syrupExtra = parseFloat(syrupOpt?.dataset.price || "0");

    const tops = getSelectedToppings();
    const topsTotal = tops.reduce((s, t) => s + (t.price || 0), 0);

    const unit_price = Math.round((sizePrice + syrupExtra + topsTotal) * 100) / 100;

    CART.push({
      product_id: currentProduct.id,
      variation_id: null,
      name: currentProduct.name,
      qty: 1,
      unit_price,
      meta: { size: sizeLabel, syrup: syrupLabel, toppings: tops },
    });

    closeModal();
    renderCart();
  });

  // ---------------- Customer picker ----------------
  function initCustomerIndex() {
    if (!elCustomerJson) return;
    try {
      CUSTOMER_INDEX = JSON.parse(elCustomerJson.textContent || "[]");
    } catch (err) {
      console.error("JC POS customer JSON parse error:", err);
      CUSTOMER_INDEX = [];
    }
  }

  function customerDisplayName(customer) {
    const first = String(customer?.first_name || "").trim();
    const last = String(customer?.last_name || "").trim();
    const full = `${first} ${last}`.trim();
    if (full) return full;
    if (String(customer?.company || "").trim()) return String(customer.company).trim();
    return `Customer #${customer?.id || ""}`;
  }

  function buildCustomerMeta(customer) {
    const parts = [];
    if (customer?.company) parts.push(`Company: ${customer.company}`);
    if (customer?.nrc) parts.push(`NRC: ${customer.nrc}`);
    if (customer?.nit) parts.push(`NIT: ${customer.nit}`);
    if (customer?.phone) parts.push(`Phone: ${customer.phone}`);
    return parts.join(" • ");
  }

  function setCustomerError(message) {
    if (!elCustomerError) return;
    if (message) {
      elCustomerError.textContent = message;
      elCustomerError.style.display = "block";
    } else {
      elCustomerError.textContent = "";
      elCustomerError.style.display = "none";
    }
  }

  function clearCustomerResults() {
    if (!elCustomerResults) return;
    elCustomerResults.innerHTML = "";
    elCustomerResults.style.display = "none";
  }

  function writeCustomerHiddenFields(customer) {
    if (elSaleCustomerId) elSaleCustomerId.value = customer?.id ? String(customer.id) : "";
    if (elSaleCustomerName) elSaleCustomerName.value = customer ? customerDisplayName(customer) : "";
    if (elSaleCustomerCompany) elSaleCustomerCompany.value = customer?.company ? String(customer.company) : "";
    if (elSaleCustomerNrc) elSaleCustomerNrc.value = customer?.nrc ? String(customer.nrc) : "";
    if (elSaleCustomerNit) elSaleCustomerNit.value = customer?.nit ? String(customer.nit) : "";
    if (elSaleCustomerAddress) elSaleCustomerAddress.value = customer?.address ? String(customer.address) : "";
    if (elSaleCustomerCity) elSaleCustomerCity.value = customer?.city ? String(customer.city) : "";
    if (elSaleCustomerPhone) elSaleCustomerPhone.value = customer?.phone ? String(customer.phone) : "";
    if (elSaleCustomerEmail) elSaleCustomerEmail.value = customer?.email ? String(customer.email) : "";
  }

  function selectCustomer(customer) {
    writeCustomerHiddenFields(customer);
    updateCustomerRequirementState();
    if (elResult) elResult.innerHTML = "";

    if (elCustomerSearch) elCustomerSearch.value = customerDisplayName(customer);
    setCustomerError("");
    clearCustomerResults();
  }

  function clearSelectedCustomer() {
    writeCustomerHiddenFields(null);
    if (elCustomerSearch) elCustomerSearch.value = "";
    updateCustomerRequirementState();
    if (elResult) elResult.innerHTML = "";
    setCustomerError("");
    clearCustomerResults();
  }

  function customerMatches(customer, query) {
    const q = normalizeText(query);
    if (!q) return true;

    const haystack = [
      customer?.first_name,
      customer?.last_name,
      customer?.company,
      customer?.nrc,
      customer?.nit,
      customer?.phone,
      customer?.email,
    ].map(normalizeText).join(" ");

    return haystack.includes(q);
  }

  function renderCustomerResults(list) {
    if (!elCustomerResults) return;

    elCustomerResults.innerHTML = "";

    if (!list.length) {
      elCustomerResults.innerHTML =
        '<div style="padding:8px 10px;color:#50575e;font-size:12px;">No matching customers found.</div>';
      elCustomerResults.style.display = "block";
      return;
    }

    list.forEach((customer) => {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "button-link";
      row.style.display = "block";
      row.style.width = "100%";
      row.style.textAlign = "left";
      row.style.padding = "8px 10px";
      row.style.borderBottom = "1px solid #f0f0f1";
      row.style.textDecoration = "none";
      row.style.color = "#1d2327";
      row.style.background = "#fff";
      row.style.cursor = "pointer";

      row.innerHTML =
        `<div style="font-weight:600;">${escapeHtml(customerDisplayName(customer))}</div>` +
        `<div style="margin-top:3px;color:#50575e;font-size:12px;">${escapeHtml(buildCustomerMeta(customer))}</div>`;

      row.addEventListener("click", () => selectCustomer(customer));
      elCustomerResults.appendChild(row);
    });

    elCustomerResults.style.display = "block";
  }

  function runCustomerSearch() {
    if (!elCustomerSearch) return;

    if (elResult) elResult.innerHTML = "";

    const q = normalizeText(elCustomerSearch.value || "");
    setCustomerError("");
    clearCustomerResults();

    if (!q) {
      setCustomerError("Enter a name, company, NRC, NIT, phone, or email to search.");
      return;
    }

    const matches = CUSTOMER_INDEX.filter((customer) => customerMatches(customer, q)).slice(0, 20);
    renderCustomerResults(matches);
  }

  function initCustomerPicker() {
    if (!elCustomerBox || !elCustomerJson) return;

    initCustomerIndex();
    elCustomerSearchBtn?.addEventListener("click", runCustomerSearch);

    elCustomerSearch?.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        runCustomerSearch();
      }
    });

    elClearCustomer?.addEventListener("click", () => {
      clearSelectedCustomer();
      elCustomerSearch?.focus();
    });

    updateCustomerRequirementState();
  }

  // ---------------- Reset sale ----------------
  function resetSaleUI() {
    if (elResult) elResult.innerHTML = "";

    CART = [];
    renderCart();

    if (elCashReceived) elCashReceived.value = "0";
    if (elChangeDue) elChangeDue.textContent = money(0);

    if (elDiscountType) elDiscountType.value = "none";
    if (elDiscountValue) elDiscountValue.value = "0";
    if (elFeeLabel) elFeeLabel.value = "";
    if (elFeeValue) elFeeValue.value = "0";

    updateTotalsUI();
  }

  elReceiptNewSale?.addEventListener("click", () => {
    resetSaleUI();
    closeReceiptAndClear();
  });

  // ---------------- Checkout ----------------
  async function checkout() {
    if (!CART.length) return;

    if (getDocType() === "CREDITO_FISCAL" && !hasSelectedCustomer()) {
      if (elResult) elResult.innerHTML = `<p class="jc-bad">Error: Select a customer for Crédito Fiscal.</p>`;
      return;
    }

    const soldCart = CART.map((x) => JSON.parse(JSON.stringify(x)));

    if (elResult) elResult.innerHTML = "<p>Processing...</p>";

    const payload = {
      cart: CART,
      document_type: getDocType(),
      discount_type: elDiscountType?.value || "none",
      discount_value: parseFloat(elDiscountValue?.value || "0") || 0,
      fee_label: elFeeLabel?.value || "",
      fee_value: feeAmount(),
      cash_received: parseNum(elCashReceived?.value || "0"),

      customer_id: parseInt(elSaleCustomerId?.value || "0", 10) || 0,
      customer_name: elSaleCustomerName?.value || "",
      customer_company: elSaleCustomerCompany?.value || "",
      customer_nrc: elSaleCustomerNrc?.value || "",
      customer_nit: elSaleCustomerNit?.value || "",
      customer_address: elSaleCustomerAddress?.value || "",
      customer_city: elSaleCustomerCity?.value || "",
      customer_phone: elSaleCustomerPhone?.value || "",
      customer_email: elSaleCustomerEmail?.value || "",
    };

    let res;
    try {
      res = await apiFetch({
        path: "/jc-pos/v1/checkout",
        method: "POST",
        data: payload,
      });
    } catch (err) {
      console.error("Checkout error:", err);
      const msg = err?.data?.message || err?.data?.error || err?.message || "Request failed";
      if (elResult) elResult.innerHTML = `<p class="jc-bad">Error: ${escapeHtml(msg)}</p>`;
      return;
    }

    if (!res?.success) {
      if (elResult) elResult.innerHTML = `<p class="jc-bad">Error: ${escapeHtml(res?.error || "Unknown")}</p>`;
      return;
    }

    const receiptHtml = buildReceiptHtml(res, soldCart);

    // Open modal immediately, then render MH/QR inside it
    openReceiptModal(receiptHtml);
    setMhUI(res);

    // Print: inject QR into printed receipt (wait a tick if needed)
    if (elReceiptPrint) {
      elReceiptPrint.onclick = () => printReceiptWithQr(receiptHtml, res);
    }

    // Modal is now the UX; don’t keep “Sale OK” box around
    if (elResult) elResult.innerHTML = "";

    // Reset cart/totals for next sale (customer stays selected)
    resetSaleUI();
  }

  // ---------------- Events ----------------
  elRefresh?.addEventListener("click", loadBootstrap);
  elCheckout?.addEventListener("click", checkout);

  elSearch?.addEventListener("input", (e) => renderProducts(e.target.value || ""));

  elDiscountType?.addEventListener("change", renderCart);
  elDiscountValue?.addEventListener("input", renderCart);
  elFeeLabel?.addEventListener("input", renderCart);
  elFeeValue?.addEventListener("input", renderCart);

  // ---------------- Boot ----------------
  updateDocUI();
  initCustomerPicker();
  loadBootstrap();
  renderCart();
})();