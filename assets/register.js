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

  // State
  let BOOTSTRAP = null;
  let MENUS = [];
  let SIZES = [];
  let TOPPINGS = [];
  let PRODUCTS = [];
  let CART = [];
  let currentMenuId = null;
  let currentProduct = null;

  // Utils
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

  function feeAmount() {
    if (!elFeeValue) return 0;
    return Math.max(0, parseFloat(elFeeValue.value || "0") || 0);
  }

  function apiGet(path) {
    return apiFetch({ path: "/jc-pos/v1" + path });
  }

  // --- Receipt helpers ---
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

  // Build receipt using DTE when available; fallback to soldCart
  function buildReceiptHtml(res, soldCart) {
    const dte = res.dte || res.dte_json || null;

    // Use DTE if backend returns it
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

    // Fallback: build from soldCart
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

    // total from UI calculation if API doesn’t send it
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

  // --- MENUS ---
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

  // --- PRODUCTS ---
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

  async function loadBootstrap() {
    elResult.innerHTML = "";
    elProducts.innerHTML = "<p>Loading...</p>";
    if (elMenus) elMenus.innerHTML = "";

    try {
      BOOTSTRAP = await apiGet("/menu");
    } catch (e) {
      console.error(e);
      elProducts.innerHTML = "<p>Failed to load POS menu.</p>";
      return;
    }

    MENUS = (BOOTSTRAP.menus || []).map((m) => ({
      id: parseInt(m.id, 10),
      name: m.name,
    }));

    SIZES = (BOOTSTRAP.sizes || []).map((s) => ({
      id: parseInt(s.id, 10),
      label: s.label,
      price_delta: parseFloat(s.price_delta || "0"),
      is_default: parseInt(s.is_default || "0", 10),
    }));

    TOPPINGS = (BOOTSTRAP.addons?.TOPPING || []).map((a) => ({
      id: String(a.id),
      name: a.name,
      price: parseFloat(a.price || "0"),
    }));

    if (!MENUS.length) {
      elProducts.innerHTML = "<p><em>No menus configured yet.</em></p>";
      return;
    }

    currentMenuId = MENUS[0].id;
    renderMenus();
    await loadMenuProducts(currentMenuId);
  }

  async function loadMenuProducts(menuId) {
    elProducts.innerHTML = "<p>Loading products...</p>";

    let data;
    try {
      data = await apiGet(`/menu/${menuId}`);
    } catch (e) {
      console.error(e);
      elProducts.innerHTML = "<p>Failed to load menu products.</p>";
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

  // --- MODAL ---
  function openModal(product) {
    currentProduct = product;
    elModalTitle.textContent = product.name;

    // Flavor (simple)
    elFlavor.innerHTML = "";
    const optF = document.createElement("option");
    optF.value = "";
    optF.textContent = "Default";
    elFlavor.appendChild(optF);

    // Sizes
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

    // Toppings
    elToppings.innerHTML = "";
    TOPPINGS.forEach((t) => {
      const row = document.createElement("label");
      row.className = "jc-top-row";
      row.innerHTML = `<input type="checkbox" data-id="${t.id}"> ${t.name} (+${money(t.price)})`;
      elToppings.appendChild(row);
    });

    updateItemTotal();
    elModal.classList.remove("jc-hidden");
    elModal.setAttribute("aria-hidden", "false");
  }

  function closeModal() {
    elModal.classList.add("jc-hidden");
    elModal.setAttribute("aria-hidden", "true");
    currentProduct = null;
  }

  function getSelectedToppings() {
    const picks = [];
    elToppings.querySelectorAll("input[type=checkbox]").forEach((cb) => {
      if (!cb.checked) return;
      const t = TOPPINGS.find((x) => x.id === cb.dataset.id);
      if (t) picks.push(t);
    });
    return picks;
  }

  function updateItemTotal() {
    if (!currentProduct) return;
    const sizeOpt = elSize.options[elSize.selectedIndex];
    const basePrice = parseFloat(sizeOpt?.dataset.price || currentProduct.price || "0");
    const tops = getSelectedToppings().reduce((s, t) => s + (t.price || 0), 0);
    elItemTotal.textContent = money(basePrice + tops);
  }

  // --- CART / TOTALS ---
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
    const fee = feeAmount();
    const tot = afterDiscount + fee;

    elSubtotal.textContent = money(sub);
    elTotal.textContent = money(tot);
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

  // --- CHECKOUT ---
  async function checkout() {
    if (!CART.length) return;

    // snapshot so receipt can use it
    const soldCart = CART.map((x) => JSON.parse(JSON.stringify(x)));

    elResult.innerHTML = "<p>Processing...</p>";

    const payload = {
      cart: CART,
      discount_type: elDiscountType?.value || "none",
      discount_value: parseFloat(elDiscountValue?.value || "0") || 0,
      fee_label: elFeeLabel ? (elFeeLabel.value || "") : "",
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
      elResult.innerHTML = `<p class="jc-bad">Error: ${escapeHtml(msg)}</p>`;
      return;
    }

    if (!res?.success) {
      elResult.innerHTML = `<p class="jc-bad">Error: ${escapeHtml(res?.error || "Unknown")}</p>`;
      return;
    }

    // reset for next sale
    CART = [];
    renderCart();
    if (elDiscountType) elDiscountType.value = "none";
    if (elDiscountValue) elDiscountValue.value = "0";
    if (elFeeLabel) elFeeLabel.value = "";
    if (elFeeValue) elFeeValue.value = "0";
    updateTotalsUI();

    // show result + receipt modal
    const mh = res.mh || {};
    const receiptHtml = buildReceiptHtml(res, soldCart);

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

    document.getElementById("jc-open-receipt")?.addEventListener("click", () => {
      openReceiptModal(receiptHtml);
    });

    // auto open receipt modal (optional) — uncomment if you want it automatic:
    // openReceiptModal(receiptHtml);

    // wire print button to print the receipt content
    if (elReceiptPrint) {
      elReceiptPrint.onclick = () => printReceipt(receiptHtml);
    }
  }

  // --- EVENTS ---
  document.getElementById("jc-modal-close")?.addEventListener("click", closeModal);
  elSize?.addEventListener("change", updateItemTotal);
  elFlavor?.addEventListener("change", updateItemTotal);
  elToppings?.addEventListener("change", updateItemTotal);

  document.getElementById("jc-add-to-cart")?.addEventListener("click", () => {
    if (!currentProduct) return;

    const sizeOpt = elSize.options[elSize.selectedIndex];
    const sizePrice = parseFloat(sizeOpt?.dataset.price || currentProduct.price || "0");
    const sizeLabel = sizeOpt?.textContent ? sizeOpt.textContent.split(" (")[0] : "Default";

    const tops = getSelectedToppings();
    const topsTotal = tops.reduce((s, t) => s + (t.price || 0), 0);
    const unit_price = Math.round((sizePrice + topsTotal) * 100) / 100;

    CART.push({
      product_id: currentProduct.id,
      variation_id: null,
      name: currentProduct.name,
      qty: 1,
      unit_price,
      meta: { size: sizeLabel, flavor: elFlavor.value, toppings: tops },
    });

    closeModal();
    renderCart();
  });

  elRefresh?.addEventListener("click", () => loadBootstrap());
  elCheckout?.addEventListener("click", checkout);
  elSearch?.addEventListener("input", (e) => renderProducts(e.target.value || ""));

  elDiscountType?.addEventListener("change", renderCart);
  elDiscountValue?.addEventListener("input", renderCart);
  elFeeLabel?.addEventListener("input", renderCart);
  elFeeValue?.addEventListener("input", renderCart);

  // receipt modal buttons
  elReceiptClose?.addEventListener("click", closeReceiptModal);
  elReceiptDone?.addEventListener("click", closeReceiptModal);
  elReceiptModal?.addEventListener("click", (e) => {
    if (e.target === elReceiptModal) closeReceiptModal(); // click backdrop closes
  });

  // Boot
  loadBootstrap();
  renderCart();
})();