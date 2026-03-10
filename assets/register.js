/* assets/register.js
 * Teavo / JC POS Register (clean single-file version)
 * Requires:
 *  - wp-api-fetch (WordPress)
 *  - QRCode lib loaded before this file (qrcode.min.js -> global QRCode)
 */
(function () {
  const apiFetch = window.wp?.apiFetch;
  if (!apiFetch || !window.JC_POS?.nonce) return;

  apiFetch.use(apiFetch.createNonceMiddleware(JC_POS.nonce));

  // ---------------- DOM ----------------
  const elMenus = document.getElementById("jc-menus");
  const elProducts = document.getElementById("jc-products");
  const elCart = document.getElementById("jc-cart");
  const elSubtotal = document.getElementById("jc-subtotal");
  const elTotal = document.getElementById("jc-total");
  const elResult = document.getElementById("jc-result");

  const elDiscountType = document.getElementById("jc-discount-type");
  const elDiscountValue = document.getElementById("jc-discount-value");
  const elFeeLabel = document.getElementById("jc-fee-label");
  const elFeeValue = document.getElementById("jc-fee-value");

  const elSearch = document.getElementById("jc-search");
  const elRefresh = document.getElementById("jc-refresh");
  const elCheckout = document.getElementById("jc-checkout");

  // Optional cash/change (only if present in HTML)
  const elCashReceived = document.getElementById("jc-cash-received");
  const elCashExact = document.getElementById("jc-cash-exact");
  const elChangeDue = document.getElementById("jc-change-due");

  // Product modal
  const elModal = document.getElementById("jc-modal");
  const elModalTitle = document.getElementById("jc-modal-title");
  const elSize = document.getElementById("jc-opt-size");
  const elFlavor = document.getElementById("jc-opt-flavor");
  const elToppings = document.getElementById("jc-opt-toppings");
  const elItemTotal = document.getElementById("jc-item-total");
  const elModalClose = document.getElementById("jc-modal-close");
  const elAddToCart = document.getElementById("jc-add-to-cart");

  // Receipt modal
  const elReceiptModal = document.getElementById("jc-receipt-modal");
  const elReceiptBody = document.getElementById("jc-receipt-body");
  const elReceiptCloseX = document.getElementById("jc-receipt-close");
  const elReceiptCloseBtn = document.getElementById("jc-receipt-close-btn");
  const elReceiptPrint = document.getElementById("jc-receipt-print");
  const elReceiptNewSale = document.getElementById("jc-receipt-new-sale");

  // MH UI inside receipt modal
  const elMhBox = document.getElementById("jc-mh-box");
  const elMhPill = document.getElementById("jc-mh-pill");
  const elMhSummary = document.getElementById("jc-mh-summary");
  const elMhDetails = document.getElementById("jc-mh-details");
  const elMhJson = document.getElementById("jc-mh-json");
  const elQrWrap = document.getElementById("jc-mh-qr-wrap");
  const elQr = document.getElementById("jc-mh-qr");

  // Document type
  const elDocRadios = document.querySelectorAll('input[name="jc_doc_type"]');
  const elDocBadge = document.getElementById("jc-doc-badge");

  // Customer picker
  const elCustomerBox = document.getElementById("jc-register-customer-box");
  const elCustomerJson = document.getElementById("jc-pos-customer-index");
  const elCustomerSearch = document.getElementById("jc-customer-search");
  const elCustomerSearchBtn = document.getElementById("jc-customer-search-btn");
  const elCustomerResults = document.getElementById("jc-customer-results");
  const elCustomerError = document.getElementById("jc-customer-error");
  const elCustomerRequirement = document.getElementById("jc-customer-requirement-text");
  const elClearCustomer = document.getElementById("jc-clear-customer");

  const elSaleCustomerId = document.getElementById("jc-sale-customer-id");
  const elSaleCustomerName = document.getElementById("jc-sale-customer-name");
  const elSaleCustomerCompany = document.getElementById("jc-sale-customer-company");
  const elSaleCustomerNrc = document.getElementById("jc-sale-customer-nrc");
  const elSaleCustomerNit = document.getElementById("jc-sale-customer-nit");
  const elSaleCustomerAddress = document.getElementById("jc-sale-customer-address");
  const elSaleCustomerCity = document.getElementById("jc-sale-customer-city");
  const elSaleCustomerPhone = document.getElementById("jc-sale-customer-phone");
  const elSaleCustomerEmail = document.getElementById("jc-sale-customer-email");

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

  // receipt session state
  let LAST_RECEIPT_HTML = "";
  let LAST_QR_URL = "";
  let LAST_RESULT = null;

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
    return afterDiscount + feeAmount();
  }

  function updateTotalsUI() {
    const sub = cartSubtotal();
    const tot = cartTotal();
    if (elSubtotal) elSubtotal.textContent = money(sub);
    if (elTotal) elTotal.textContent = money(tot);
    updateChangeUI();
  }

  function updateChangeUI() {
    if (!elCashReceived || !elChangeDue) return;

    const total = cartTotal();
    const cash = parseNum(elCashReceived.value);
    const change = Math.max(0, Math.round((cash - total) * 100) / 100);

    elChangeDue.textContent = money(change);
    elChangeDue.style.color = cash > 0 && cash < total ? "#d63638" : "";
  }

  // ---------------- Document type ----------------
  function getDocType() {
    const checked = document.querySelector('input[name="jc_doc_type"]:checked');
    const v = (checked?.value || "CONSUMIDOR_FINAL").toUpperCase();
    return v === "CREDITO_FISCAL" ? "CREDITO_FISCAL" : "CONSUMIDOR_FINAL";
  }

  function updateDocUI() {
    const dt = getDocType();
    if (elDocBadge) elDocBadge.textContent = dt === "CREDITO_FISCAL" ? "Requiere datos fiscales" : "Venta rápida";
    updateCustomerRequirementState();
  }

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
    return allAddons.filter((a) => {
      const t = String(a.addon_type || a.type || a.group || "").toUpperCase();
      return t === want;
    });
  }

  // ---------------- Receipt modal ----------------
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
    LAST_RECEIPT_HTML = "";
    LAST_QR_URL = "";
    LAST_RESULT = null;

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

  // Print window
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

  // ---- QR URL building ----
  function parseMhFhProcesamientoToYmd(fh) {
    const s = String(fh || "").trim(); // "06/03/2026 13:12:42"
    const m = s.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
    if (!m) return "";
    return `${m[3]}-${m[2]}-${m[1]}`;
  }

  function ymdToday() {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
  }

  // Official:
  // https://admin.factura.gob.sv/consultaPublica?ambiente={00|01}&codGen={uuid}&fechaEmi={YYYY-MM-DD}
  function buildMhQrUrl(res) {
    const mh = res?.mh || {};
    const dte = res?.dte || res?.dte_json || null;

    const ambiente = String(dte?.identificacion?.ambiente || mh?.ambiente || "00").trim() || "00";
    const codGen = String(dte?.identificacion?.codigoGeneracion || mh?.codigoGeneracion || "").trim();

    const fechaEmi =
      String(dte?.identificacion?.fecEmi || "").trim() ||
      parseMhFhProcesamientoToYmd(mh?.fhProcesamiento) ||
      String(mh?.fechaEmi || "").trim() ||
      ymdToday();

    if (!codGen || !fechaEmi) return "";

    const base = "https://admin.factura.gob.sv/consultaPublica";
    return `${base}?ambiente=${encodeURIComponent(ambiente)}&codGen=${encodeURIComponent(codGen)}&fechaEmi=${encodeURIComponent(fechaEmi)}`;
  }

  function renderQr(el, text) {
    if (!el) return;
    el.innerHTML = "";
    if (!text) return;

    const QR = typeof window.QRCode === "function" ? window.QRCode : typeof QRCode === "function" ? QRCode : null;
    if (QR) {
      // eslint-disable-next-line no-new
      new QR(el, { text, width: 160, height: 160 });
      return;
    }

    // fallback: show the URL
    el.innerHTML = `<div style="font-size:12px;color:#50575e;word-break:break-all;">${escapeHtml(text)}</div>`;
  }

  function getQrDataUrlFromEl(el) {
    if (!el) return "";
    const img = el.querySelector("img");
    if (img?.src?.startsWith("data:")) return img.src;

    const canvas = el.querySelector("canvas");
    if (canvas?.toDataURL) {
      try {
        return canvas.toDataURL("image/png");
      } catch (_) {}
    }
    return "";
  }

  function generateQrDataUrlFromUrl(qrUrl) {
    return new Promise((resolve) => {
      if (!qrUrl) return resolve("");

      const QR = typeof window.QRCode === "function" ? window.QRCode : typeof QRCode === "function" ? QRCode : null;
      if (!QR) return resolve("");

      const tmp = document.createElement("div");
      tmp.style.position = "fixed";
      tmp.style.left = "-9999px";
      tmp.style.top = "-9999px";
      document.body.appendChild(tmp);

      try {
        // eslint-disable-next-line no-new
        new QR(tmp, { text: qrUrl, width: 160, height: 160 });
      } catch (e) {
        document.body.removeChild(tmp);
        return resolve("");
      }

      setTimeout(() => {
        const data = getQrDataUrlFromEl(tmp);
        document.body.removeChild(tmp);
        resolve(data || "");
      }, 30);
    });
  }

  function injectQrIntoReceiptHtml(receiptHtml, qrDataUrl, qrUrl) {
    if (!qrDataUrl && !qrUrl) return receiptHtml;

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
          <strong>QR:</strong> ${escapeHtml(qrUrl)}
        </div>
      `;

    if (receiptHtml.includes("Gracias por su compra")) {
      return receiptHtml.replace("Gracias por su compra", `${qrBlock}Gracias por su compra`);
    }
    return receiptHtml + qrBlock;
  }

  // Render MH UI inside receipt modal
  function setMhUI(res) {
    const mh = res?.mh || {};
    if (!elMhBox) return;

    const estado = String(mh?.estado || mh?.mh_status || "").toUpperCase();
    const codigo = String(mh?.codigoMsg || "");
    const desc = String(mh?.descripcionMsg || mh?.error || "");

    // pill
    if (elMhPill) {
      let bg = "#f0f0f1",
        color = "#1d2327";
      if (estado === "PROCESADO") {
        bg = "#d4edda";
        color = "#145a32";
      }
      if (estado === "RECHAZADO") {
        bg = "#f8d7da";
        color = "#842029";
      }
      if (estado === "FAILED" || !estado) {
        bg = "#fff3cd";
        color = "#664d03";
      }
      elMhPill.style.background = bg;
      elMhPill.style.color = color;
      elMhPill.textContent = estado || "—";
    }

    // summary
    if (elMhSummary) {
      const parts = [];
      if (codigo) parts.push(`Code: ${codigo}`);
      if (desc) parts.push(desc);
      elMhSummary.textContent = parts.length ? parts.join(" — ") : "—";
    }

    // QR (only show when processed)
    const qrUrl = buildMhQrUrl(res);
    LAST_QR_URL = qrUrl;

    if (elQrWrap && elQr) {
      if (estado === "PROCESADO" && qrUrl) {
        elQrWrap.style.display = "block";
        renderQr(elQr, qrUrl);
      } else {
        elQrWrap.style.display = "none";
        elQr.innerHTML = "";
      }
    }

    // debug json
    if (elMhJson) elMhJson.textContent = JSON.stringify(mh || {}, null, 2);
    if (elMhDetails) elMhDetails.open = estado === "RECHAZADO" || estado === "FAILED";
  }

  async function printReceiptWithQr() {
    const receiptHtml = LAST_RECEIPT_HTML;
    const qrUrl = LAST_QR_URL;
    if (!receiptHtml) return;

    const qrDataUrl = await generateQrDataUrlFromUrl(qrUrl);
    const printable = injectQrIntoReceiptHtml(receiptHtml, qrDataUrl, qrUrl);
    printReceipt(printable);
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
        ? lines
            .map((l) => {
              const qty = l.cantidad ?? 1;
              const desc = escapeHtml(l.descripcion ?? "");
              const price = Number(l.precioUni ?? 0);
              return `<tr><td>${qty}x ${desc}</td><td style="text-align:right">${money(price)}</td></tr>`;
            })
            .join("")
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

    // fallback
    const rows = (soldCart || []).length
      ? soldCart
          .map((l) => {
            const qty = Number(l.qty || 1);
            const name = escapeHtml(l.name || "");
            const lineTotal = Number(l.unit_price || 0) * qty;
            return `<tr><td>${qty}x ${name}</td><td style="text-align:right">${money(lineTotal)}</td></tr>`;
          })
          .join("")
      : `<tr><td colspan="2"><em>No items</em></td></tr>`;

    const tot = cartTotal();

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

  // ---------------- UI ----------------
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

      bottom.querySelector("button")?.addEventListener("click", () => openProductModal(p));

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
    TOPPINGS = pickByType(allAddons, "TOPPING").map((a) => ({ id: String(a.id), name: a.name, price: parseFloat(a.price || "0") }));
    SYRUPS = pickByType(allAddons, "SYRUP").map((a) => ({ id: String(a.id), name: a.name, price: parseFloat(a.price || "0") }));

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

  function openProductModal(product) {
    currentProduct = product;
    if (elModalTitle) elModalTitle.textContent = product.name;

    // syrups
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

    // sizes
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

    // toppings
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

  function closeProductModal() {
    elModal?.classList.add("jc-hidden");
    elModal?.setAttribute("aria-hidden", "true");
    currentProduct = null;
  }

  // ---------------- Customers ----------------
  function initCustomerIndex() {
    if (!elCustomerJson) return;
    try {
      CUSTOMER_INDEX = JSON.parse(elCustomerJson.textContent || "[]");
    } catch (err) {
      console.error("Customer JSON parse error:", err);
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
    updateCustomerRequirementState();
    if (elCustomerSearch) elCustomerSearch.value = "";
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
    ]
      .map(normalizeText)
      .join(" ");

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

    const matches = CUSTOMER_INDEX.filter((c) => customerMatches(c, q)).slice(0, 20);
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
  function resetSaleUI({ clearCustomer = true } = {}) {
    if (elResult) elResult.innerHTML = "";

    CART = [];
    renderCart();

    if (elCashReceived) elCashReceived.value = "0";
    if (elChangeDue) elChangeDue.textContent = money(0);

    if (elDiscountType) elDiscountType.value = "none";
    if (elDiscountValue) elDiscountValue.value = "0";
    if (elFeeLabel) elFeeLabel.value = "";
    if (elFeeValue) elFeeValue.value = "0";

    if (clearCustomer) clearSelectedCustomer();

    updateTotalsUI();
  }

  // ---------------- Checkout ----------------
  async function checkout() {
    if (!CART.length) return;

    if (getDocType() === "CREDITO_FISCAL" && !hasSelectedCustomer()) {
      if (elResult) elResult.innerHTML = `<p class="jc-bad">Error: Select a customer for Crédito Fiscal.</p>`;
      return;
    }

    const soldCart = CART.map((x) => JSON.parse(JSON.stringify(x)));

    if (elResult) elResult.innerHTML = "<p>Processing...</p>";
    if (elCheckout) elCheckout.disabled = true;

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
      if (elCheckout) elCheckout.disabled = false;
      return;
    }

    if (elCheckout) elCheckout.disabled = false;

    if (!res?.success) {
      if (elResult) elResult.innerHTML = `<p class="jc-bad">Error: ${escapeHtml(res?.error || "Unknown")}</p>`;
      return;
    }

    // Build receipt + open modal
    const receiptHtml = buildReceiptHtml(res, soldCart);
    LAST_RECEIPT_HTML = receiptHtml;
    LAST_RESULT = res;
    LAST_QR_URL = buildMhQrUrl(res);

    openReceiptModal(receiptHtml);
    setMhUI(res);

    // print uses QR (or URL fallback)
    if (elReceiptPrint) elReceiptPrint.onclick = printReceiptWithQr;

    // clear bottom area; modal is now the UX
    if (elResult) elResult.innerHTML = "";

    // reset register for next sale
    resetSaleUI({ clearCustomer: true });

    /***************************************************************************************
     * Print the receipt as soon as it completes the sale
     ***************************************************************************************/
    // Auto-print thermal receipt (80mm) immediately after sale
    if (res.receipt_url) {
    // Use a stable window name so it reuses the same popup
      const w = window.open(res.receipt_url, "jc_thermal_receipt", "width=420,height=720");
    // If popups are blocked, show a clear message
    if (!w && elResult) {
      elResult.innerHTML = `<p class="jc-bad">Popup blocked. Please allow popups to auto-print receipt.</p>`;
    }
  }
    
  }

  // ---------------- Events ----------------
  elModalClose?.addEventListener("click", closeProductModal);

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

    closeProductModal();
    renderCart();
  });

  elSize?.addEventListener("change", updateItemTotal);
  elFlavor?.addEventListener("change", updateItemTotal);
  elToppings?.addEventListener("change", updateItemTotal);

  elRefresh?.addEventListener("click", loadBootstrap);
  elCheckout?.addEventListener("click", checkout);
  elSearch?.addEventListener("input", (e) => renderProducts(e.target.value || ""));

  elDiscountType?.addEventListener("change", renderCart);
  elDiscountValue?.addEventListener("input", renderCart);
  elFeeLabel?.addEventListener("input", renderCart);
  elFeeValue?.addEventListener("input", renderCart);

  elCashReceived?.addEventListener("input", updateChangeUI);
  elCashExact?.addEventListener("click", () => {
    if (!elCashReceived) return;
    elCashReceived.value = String(Math.round(cartTotal() * 100) / 100);
    updateChangeUI();
  });

  elDocRadios?.forEach((r) => r.addEventListener("change", updateDocUI));

  // Receipt modal controls
  elReceiptCloseX?.addEventListener("click", closeReceiptAndClear);
  elReceiptCloseBtn?.addEventListener("click", closeReceiptAndClear);

  elReceiptNewSale?.addEventListener("click", () => {
    resetSaleUI({ clearCustomer: true });
    closeReceiptAndClear();
  });

  // esc closes receipt modal
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && elReceiptModal && !elReceiptModal.classList.contains("jc-hidden")) {
      closeReceiptAndClear();
    }
  });

  // ---------------- Boot ----------------
  updateDocUI();
  initCustomerPicker();
  loadBootstrap();
  renderCart();

  // Optional debug (uncomment if you want console access)
  // window.JC_POS_DEBUG = {
  //   buildMhQrUrl,
  //   getLast: () => LAST_RESULT,
  //   getQr: () => LAST_QR_URL,
  // };
})();