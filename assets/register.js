(function () {
  const apiFetch = window.wp?.apiFetch;
  if (!apiFetch) return;

  // Nonce middleware (WP admin)
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

  const elSearch = document.getElementById("jc-search");
  const elRefresh = document.getElementById("jc-refresh");
  const elCheckout = document.getElementById("jc-checkout");

  const elFeeLabel = document.getElementById("jc-fee-label");
  const elFeeValue = document.getElementById("jc-fee-value");

  // State
  let BOOTSTRAP = null; // {menus, sizes, addons}
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

  function feeAmount() {
    if (!elFeeValue) return 0;
    return Math.max(0, parseFloat(elFeeValue.value || "0") || 0);
  }

  function apiGet(path) {
    return apiFetch({ path: "/jc-pos/v1" + path });
  }

  // --- MENUS ---
  function renderMenus() {
    if (!elMenus) return;

    elMenus.innerHTML = "";
    MENUS.forEach((m) => {
      const btn = document.createElement("button");
      btn.type = "button";

      const active = m.id === currentMenuId;
      btn.className = "button jc-menu-btn" + (active ? " button-primary" : "");
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
    const list = q
      ? PRODUCTS.filter((p) => p.name.toLowerCase().includes(q))
      : PRODUCTS;

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
      image: m.image_url || null,
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

    // Flavor (keep simple for now)
    elFlavor.innerHTML = "";
    const optF = document.createElement("option");
    optF.value = "";
    optF.textContent = "Default";
    elFlavor.appendChild(optF);

    // Sizes: base + delta
    elSize.innerHTML = "";
    const base = parseFloat(product.price || "0");

    if (SIZES.length) {
      SIZES.forEach((s) => {
        const delta = parseFloat(s.price_delta || "0");
        const finalPrice = Math.round((base + delta) * 100) / 100;

        const opt = document.createElement("option");
        opt.value = String(s.id);
        opt.dataset.price = String(finalPrice);
        opt.dataset.delta = String(delta);
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
    return CART.reduce((s, l) => s + l.unit_price * l.qty, 0);
  }

  function applyDiscount(subtotal) {
    const t = elDiscountType.value;
    const v = parseFloat(elDiscountValue.value || "0") || 0;
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
      if (l.meta?.flavor) meta.push(`Flavor: ${l.meta.flavor}`);
      if (l.meta?.toppings?.length) meta.push(`Toppings: ${l.meta.toppings.map((t) => t.name).join(", ")}`);

      row.innerHTML = `
        <div>
          <strong>${l.name}</strong><br>
          <small>${meta.join(" | ")}</small>
        </div>
        <div>
          <input type="number" min="0.25" step="0.25" value="${l.qty}" class="jc-qty">
          <div>${money(l.unit_price)}</div>
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

    elResult.innerHTML = "<p>Processing...</p>";

    const payload = {
      cart: CART,
      discount_type: elDiscountType.value,
      discount_value: parseFloat(elDiscountValue.value || "0") || 0,
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
      elResult.innerHTML = `<p class="jc-bad">Error: ${msg}</p>`;
      return;
    }

    if (!res.success) {
      elResult.innerHTML = `<p class="jc-bad">Error: ${res.error || "Unknown"}</p>`;
      return;
    }

    CART = [];
    renderCart();

    elResult.innerHTML = `
      <div class="jc-good">
        <div><strong>Sale OK</strong></div>
        <div>Invoice ID: ${res.invoice_id}</div>
        <div>Ticket #: ${res.ticket_number ?? "-"}</div>
        <div>MH: ${JSON.stringify(res.mh || {})}</div>
      </div>
    `;
  }

  // --- EVENTS ---
  document.getElementById("jc-modal-close").addEventListener("click", closeModal);
  elSize.addEventListener("change", updateItemTotal);
  elFlavor.addEventListener("change", updateItemTotal);
  elToppings.addEventListener("change", updateItemTotal);

  document.getElementById("jc-add-to-cart").addEventListener("click", () => {
    if (!currentProduct) return;

    const sizeOpt = elSize.options[elSize.selectedIndex];
    const sizePrice = parseFloat(sizeOpt?.dataset.price || currentProduct.price || "0");
    const sizeLabel = sizeOpt?.textContent ? sizeOpt.textContent.split(" (")[0] : "Default";

    const tops = getSelectedToppings();
    const topsTotal = tops.reduce((s, t) => s + t.price, 0);
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

  elRefresh.addEventListener("click", () => {
    if (currentMenuId) loadMenuProducts(currentMenuId);
    else loadBootstrap();
  });

  elCheckout.addEventListener("click", checkout);

  elSearch.addEventListener("input", (e) => renderProducts(e.target.value || ""));

  elDiscountType.addEventListener("change", renderCart);
  elDiscountValue.addEventListener("input", renderCart);

  if (elFeeValue) elFeeValue.addEventListener("input", renderCart);
  if (elFeeLabel) elFeeLabel.addEventListener("input", renderCart);

  // Boot
  loadBootstrap();
  renderCart();
})();