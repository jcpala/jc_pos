(function () {
  const apiFetch = window.wp?.apiFetch;
  if (!apiFetch || !window.JC_POS?.nonce) return;

  apiFetch.use(apiFetch.createNonceMiddleware(JC_POS.nonce));

  // DOM
  const elMenus = document.getElementById("jc-menus");
  const elProducts = document.getElementById("jc-products");
  const elCart = document.getElementById("jc-cart");
  const elSubtotal = document.getElementById("jc-subtotal");
  const elTotal = document.getElementById("jc-total");
  const elResult = document.getElementById("jc-result");

  const elModal = document.getElementById("jc-modal");
  const elModalTitle = document.getElementById("jc-modal-title");
  const elSize = document.getElementById("jc-opt-size");
  const elFlavor = document.getElementById("jc-opt-flavor");
  const elToppings = document.getElementById("jc-opt-toppings");
  const elItemTotal = document.getElementById("jc-item-total");

  const elDiscountType = document.getElementById("jc-discount-type");
  const elDiscountValue = document.getElementById("jc-discount-value");
  const elFeeLabel = document.getElementById("jc-fee-label");
  const elFeeValue = document.getElementById("jc-fee-value");

  const elSearch = document.getElementById("jc-search");
  const elRefresh = document.getElementById("jc-refresh");
  const elCheckout = document.getElementById("jc-checkout");

  // Receipt modal DOM
  const elReceiptModal = document.getElementById("jc-receipt-modal");
  const elReceiptBody = document.getElementById("jc-receipt-body");
  const elReceiptClose = document.getElementById("jc-receipt-close");
  const elReceiptPrint = document.getElementById("jc-receipt-print");
  const elReceiptDone = document.getElementById("jc-receipt-done");

  // Document type DOM (NEW)
  const elDocRadios = document.querySelectorAll('input[name="jc_doc_type"]');
  const elDocBadge = document.getElementById("jc-doc-badge");

  // State
  let MENUS = [];
  let SIZES = [];
  let TOPPINGS = [];
  let SYRUPS = [];
  let PRODUCTS = [];
  let CART = [];
  let currentMenuId = null;
  let currentProduct = null;

  // ---------- utils ----------
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

  function updateTotalsUI() {
    const sub = cartSubtotal();
    const afterDiscount = applyDiscount(sub);
    const tot = afterDiscount + feeAmount();
    if (elSubtotal) elSubtotal.textContent = money(sub);
    if (elTotal) elTotal.textContent = money(tot);
  }

  // ---------- Document type (NEW) ----------
  function getDocType() {
    const checked = document.querySelector('input[name="jc_doc_type"]:checked');
    const v = (checked?.value || "CONSUMIDOR_FINAL").toUpperCase();
    return v === "CREDITO_FISCAL" ? "CREDITO_FISCAL" : "CONSUMIDOR_FINAL";
  }

  function updateDocUI() {
    const dt = getDocType();
    if (!elDocBadge) return;
    elDocBadge.textContent = dt === "CREDITO_FISCAL" ? "Requiere datos fiscales" : "Venta rápida";
  }

  elDocRadios?.forEach((r) => r.addEventListener("change", updateDocUI));

  // Normalize addons into a flat array
  function normalizeAddons(addons) {
    if (!addons) return [];

    // object keyed by type
    if (!Array.isArray(addons) && typeof addons === "object") {
      const flat = [];
      Object.keys(addons).forEach((k) => {
        if (Array.isArray(addons[k])) {
          addons[k].forEach((a) => flat.push({ ...a, addon_type: a.addon_type || a.type || k }));
        }
      });
      return flat;
    }

    // flat array
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

  // ---------- receipt ----------
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

  elReceiptClose?.addEventListener("click", closeReceiptModal);
  elReceiptDone?.addEventListener("click", closeReceiptModal);

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

    // fallback from soldCart
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

  // ---------- UI ----------
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
      bottom.innerHTML = `<span>${money(p.price)}</span> <button class="button button-small">🛒</button>`;
      bottom.querySelector("button").addEventListener("click", () => openModal(p));

      card.appendChild(img);
      card.appendChild(name);
      card.appendChild(bottom);
      elProducts.appendChild(card);
    });
  }

  function renderCart() {
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
          <button class="button button-small jc-remove">X</button>
        </div>
      `;

      row.querySelector(".jc-remove").addEventListener("click", () => {
        CART.splice(idx, 1);
        renderCart();
      });

      row.querySelector(".jc-qty").addEventListener("change", (e) => {
        l.qty = parseFloat(e.target.value || "1") || 1;
        renderCart();
      });

      elCart.appendChild(row);
    });

    updateTotalsUI();
  }

  // ---------- data loading ----------
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

  // ---------- modal ----------
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

    // SYRUP select
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

  // ---------- checkout ----------
  async function checkout() {
    if (!CART.length) return;

    const soldCart = CART.map((x) => JSON.parse(JSON.stringify(x)));
    if (elResult) elResult.innerHTML = "<p>Processing...</p>";
    
    const docType =
    document.querySelector('input[name="jc_doc_type"]:checked')?.value ||
    "CONSUMIDOR_FINAL"


    const payload = {
      cart: CART,
      document_type: getDocType(),
      discount_type: elDiscountType?.value || "none",
      discount_value: parseFloat(elDiscountValue?.value || "0") || 0,
      fee_label: elFeeLabel?.value || "",
      fee_value: feeAmount(),
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
      const msg = err?.data?.message || err?.message || "Request failed";
      if (elResult) elResult.innerHTML = `<p class="jc-bad">Error: ${escapeHtml(msg)}</p>`;
      return;
    }

    if (!res?.success) {
      if (elResult) elResult.innerHTML = `<p class="jc-bad">Error: ${escapeHtml(res?.error || "Unknown")}</p>`;
      return;
    }

    const receiptHtml = buildReceiptHtml(res, soldCart);
    const mh = res.mh || {};

    // reset for next sale
    CART = [];
    renderCart();
    if (elDiscountType) elDiscountType.value = "none";
    if (elDiscountValue) elDiscountValue.value = "0";
    if (elFeeLabel) elFeeLabel.value = "";
    if (elFeeValue) elFeeValue.value = "0";
    updateTotalsUI();

    if (elResult) {
      elResult.innerHTML = `
        <div class="jc-good">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <strong>Sale OK</strong>
            <button type="button" class="button" id="jc-open-receipt">Receipt</button>
          </div>
          <div style="margin-top:6px;">Ticket: ${escapeHtml(String(res.ticket_number ?? ""))}</div>
          <div>Invoice: ${escapeHtml(String(res.invoice_id ?? ""))}</div>
          <details style="margin-top:8px">
            <summary>MH response</summary>
            <pre style="white-space:pre-wrap">${escapeHtml(JSON.stringify(mh, null, 2))}</pre>
          </details>
        </div>
      `;
    }

    document.getElementById("jc-open-receipt")?.addEventListener("click", () => {
      openReceiptModal(receiptHtml);
    });

    // Print button prints the same receipt html
    if (elReceiptPrint) elReceiptPrint.onclick = () => printReceipt(receiptHtml);
  }

  // ---------- events ----------
  document.getElementById("jc-modal-close")?.addEventListener("click", closeModal);
  elSize?.addEventListener("change", updateItemTotal);
  elFlavor?.addEventListener("change", updateItemTotal);
  elToppings?.addEventListener("change", updateItemTotal);

  document.getElementById("jc-add-to-cart")?.addEventListener("click", () => {
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
      meta: {
        size: sizeLabel,
        syrup: syrupLabel,
        toppings: tops,
      },
    });

    closeModal();
    renderCart();
  });

  elRefresh?.addEventListener("click", loadBootstrap);
  elCheckout?.addEventListener("click", checkout);
  elSearch?.addEventListener("input", (e) => renderProducts(e.target.value || ""));

  elDiscountType?.addEventListener("change", renderCart);
  elDiscountValue?.addEventListener("input", renderCart);
  elFeeLabel?.addEventListener("input", renderCart);
  elFeeValue?.addEventListener("input", renderCart);

  // boot
  updateDocUI(); // ✅ NEW
  loadBootstrap();
  renderCart();

  (function () {
    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    onReady(function () {
        var customerBox = document.getElementById('jc-register-customer-box');
        var jsonEl = document.getElementById('jc-pos-customer-index');

        if (!customerBox || !jsonEl) {
            return;
        }

        var customers = [];
        try {
            customers = JSON.parse(jsonEl.textContent || '[]');
        } catch (err) {
            customers = [];
        }

        var searchInput = document.getElementById('jc-customer-search');
        var searchBtn = document.getElementById('jc-customer-search-btn');
        var resultsBox = document.getElementById('jc-customer-results');
        var errorBox = document.getElementById('jc-customer-error');
        var selectedBox = document.getElementById('jc-selected-customer');
        var selectedName = document.getElementById('jc-selected-customer-name');
        var selectedMeta = document.getElementById('jc-selected-customer-meta');
        var clearBtn = document.getElementById('jc-clear-customer');
        var requirementText = document.getElementById('jc-customer-requirement-text');

        var hiddenFields = {
            id: document.getElementById('jc-sale-customer-id'),
            name: document.getElementById('jc-sale-customer-name'),
            company: document.getElementById('jc-sale-customer-company'),
            nrc: document.getElementById('jc-sale-customer-nrc'),
            nit: document.getElementById('jc-sale-customer-nit'),
            address: document.getElementById('jc-sale-customer-address'),
            city: document.getElementById('jc-sale-customer-city'),
            phone: document.getElementById('jc-sale-customer-phone'),
            email: document.getElementById('jc-sale-customer-email')
        };

        var documentTypeRadios = Array.prototype.slice.call(
            document.querySelectorAll('input[type="radio"][name="document_type"]')
        );

        function getCheckedDocumentType() {
            for (var i = 0; i < documentTypeRadios.length; i++) {
                if (documentTypeRadios[i].checked) {
                    return documentTypeRadios[i].value;
                }
            }
            return '';
        }

        function isCreditoFiscal() {
            var value = (getCheckedDocumentType() || '').toString().trim().toUpperCase();

            if (!value) {
                return false;
            }

            return value.indexOf('CREDITO') !== -1 && value.indexOf('FISCAL') !== -1;
        }

        function normalize(value) {
            return (value || '').toString().trim().toLowerCase();
        }

        function escapeHtml(value) {
            return (value || '')
                .toString()
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function customerDisplayName(customer) {
            var first = (customer.first_name || '').toString().trim();
            var last = (customer.last_name || '').toString().trim();
            var full = (first + ' ' + last).trim();

            if (full !== '') {
                return full;
            }

            if ((customer.company || '').toString().trim() !== '') {
                return customer.company.toString().trim();
            }

            return 'Customer #' + (customer.id || '');
        }

        function buildCustomerMeta(customer) {
            var parts = [];

            if (customer.company) {
                parts.push('Company: ' + customer.company);
            }
            if (customer.nrc) {
                parts.push('NRC: ' + customer.nrc);
            }
            if (customer.nit) {
                parts.push('NIT: ' + customer.nit);
            }
            if (customer.phone) {
                parts.push('Phone: ' + customer.phone);
            }

            return parts.join(' • ');
        }

        function setError(message) {
            if (!errorBox) {
                return;
            }

            if (message) {
                errorBox.textContent = message;
                errorBox.style.display = 'block';
            } else {
                errorBox.textContent = '';
                errorBox.style.display = 'none';
            }
        }

        function clearResults() {
            if (!resultsBox) {
                return;
            }
            resultsBox.innerHTML = '';
            resultsBox.style.display = 'none';
        }

        function fillHiddenFields(customer) {
            hiddenFields.id.value = customer && customer.id ? String(customer.id) : '';
            hiddenFields.name.value = customer ? customerDisplayName(customer) : '';
            hiddenFields.company.value = customer && customer.company ? String(customer.company) : '';
            hiddenFields.nrc.value = customer && customer.nrc ? String(customer.nrc) : '';
            hiddenFields.nit.value = customer && customer.nit ? String(customer.nit) : '';
            hiddenFields.address.value = customer && customer.address ? String(customer.address) : '';
            hiddenFields.city.value = customer && customer.city ? String(customer.city) : '';
            hiddenFields.phone.value = customer && customer.phone ? String(customer.phone) : '';
            hiddenFields.email.value = customer && customer.email ? String(customer.email) : '';
        }

        function selectCustomer(customer) {
            fillHiddenFields(customer);

            if (selectedName) {
                selectedName.textContent = customerDisplayName(customer);
            }
            if (selectedMeta) {
                selectedMeta.textContent = buildCustomerMeta(customer);
            }
            if (selectedBox) {
                selectedBox.style.display = 'block';
            }

            setError('');
            clearResults();

            if (searchInput) {
                searchInput.value = customerDisplayName(customer);
            }
        }

        function clearSelectedCustomer() {
            fillHiddenFields(null);

            if (selectedName) {
                selectedName.textContent = '';
            }
            if (selectedMeta) {
                selectedMeta.textContent = '';
            }
            if (selectedBox) {
                selectedBox.style.display = 'none';
            }

            setError('');

            if (searchInput) {
                searchInput.value = '';
            }
        }

        function matchesCustomer(customer, query) {
            var q = normalize(query);

            if (q === '') {
                return true;
            }

            var haystack = [
                customer.first_name,
                customer.last_name,
                customer.company,
                customer.nrc,
                customer.nit,
                customer.phone,
                customer.email
            ].map(normalize).join(' ');

            return haystack.indexOf(q) !== -1;
        }

        function renderResults(list) {
            if (!resultsBox) {
                return;
            }

            resultsBox.innerHTML = '';

            if (!list.length) {
                resultsBox.innerHTML =
                    '<div style="padding:8px 10px;color:#50575e;font-size:12px;">No matching customers found.</div>';
                resultsBox.style.display = 'block';
                return;
            }

            for (var i = 0; i < list.length; i++) {
                (function (customer) {
                    var row = document.createElement('button');
                    row.type = 'button';
                    row.className = 'button-link';
                    row.style.display = 'block';
                    row.style.width = '100%';
                    row.style.textAlign = 'left';
                    row.style.padding = '8px 10px';
                    row.style.borderBottom = '1px solid #f0f0f1';
                    row.style.textDecoration = 'none';
                    row.style.color = '#1d2327';
                    row.style.background = '#fff';
                    row.style.cursor = 'pointer';

                    row.innerHTML =
                        '<div style="font-weight:600;">' + escapeHtml(customerDisplayName(customer)) + '</div>' +
                        '<div style="margin-top:3px;color:#50575e;font-size:12px;">' +
                        escapeHtml(buildCustomerMeta(customer)) +
                        '</div>';

                    row.addEventListener('click', function () {
                        selectCustomer(customer);
                    });

                    resultsBox.appendChild(row);
                })(list[i]);
            }

            resultsBox.style.display = 'block';
        }

        function runCustomerSearch() {
            var query = searchInput ? searchInput.value : '';
            var q = normalize(query);

            setError('');
            clearResults();

            if (q === '') {
                setError('Enter a name, company, NRC, NIT, phone, or email to search.');
                return;
            }

            var matches = customers.filter(function (customer) {
                return matchesCustomer(customer, q);
            }).slice(0, 20);

            renderResults(matches);
        }

        function updateRequirementState() {
            if (!requirementText) {
                return;
            }

            if (isCreditoFiscal()) {
                requirementText.textContent = 'Crédito Fiscal requires a selected customer before completing the sale.';
                requirementText.style.color = '#d63638';
            } else {
                requirementText.textContent = 'Optional for Consumidor Final. Required for Crédito Fiscal.';
                requirementText.style.color = '#50575e';
            }
        }

        if (searchBtn) {
            searchBtn.addEventListener('click', function () {
                try {
                    runCustomerSearch();
                } catch (err) {
                    console.error('JC POS customer search error:', err);
                }
            });
        }

        if (searchInput) {
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    runCustomerSearch();
                }
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                clearSelectedCustomer();
                clearResults();

                if (searchInput) {
                    searchInput.focus();
                }
            });
        }

        for (var i = 0; i < documentTypeRadios.length; i++) {
            documentTypeRadios[i].addEventListener('change', updateRequirementState);
        }

        updateRequirementState();
    });
})();









})();