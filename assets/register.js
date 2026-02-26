(function () {
    const apiFetch = window.wp?.apiFetch;
    if (!apiFetch) return;
  
    apiFetch.use(apiFetch.createNonceMiddleware(JC_POS.nonce));
  
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
  
    let PRODUCTS = [];
    let TOPPINGS = [];
    let CART = [];
    let currentProduct = null;
  
    function money(n) {
      return "$" + (Math.round(n * 100) / 100).toFixed(2);
    }
  
    function openModal(product) {
      currentProduct = product;
      elModalTitle.textContent = product.name;
      elFlavor.value = "";
      elSize.innerHTML = "";

      (product.sizes || []).forEach(s => {
        const opt = document.createElement("option");
        opt.value = String(s.variation_id || "");
        opt.dataset.price = String(s.price);
        opt.textContent = `${s.label} (${money(s.price)})`;
        elSize.appendChild(opt);
      });
      
      // fallback if none
      if (!elSize.options.length) {
        const opt = document.createElement("option");
        opt.value = "";
        opt.dataset.price = String(product.price);
        opt.textContent = `Default (${money(product.price)})`;
        elSize.appendChild(opt);
      }


  
      // toppings
      elToppings.innerHTML = "";
      TOPPINGS.forEach(t => {
        const row = document.createElement("label");
        row.className = "jc-top-row";
        row.innerHTML = `<input type="checkbox" data-id="${t.id}" data-price="${t.price}"> ${t.name} (+${money(t.price)})`;
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
      elToppings.querySelectorAll("input[type=checkbox]").forEach(cb => {
        if (cb.checked) {
          const t = TOPPINGS.find(x => x.id === cb.dataset.id);
          if (t) picks.push(t);
        }
      });
      return picks;
    }
  
    function updateItemTotal() {
        if (!currentProduct) return;
      
        const sizeOpt = elSize.options[elSize.selectedIndex];
        const basePrice = parseFloat(sizeOpt?.dataset.price || currentProduct.price || "0");
      
        const tops = getSelectedToppings().reduce((s, t) => s + t.price, 0);
      
        const total = basePrice + tops;
        elItemTotal.textContent = money(total);
      }
  
    function renderProducts() {
      elProducts.innerHTML = "";
      PRODUCTS.forEach(p => {
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
  
    function cartSubtotal() {
      return CART.reduce((s, l) => s + (l.unit_price * l.qty), 0);
    }
  
    function applyDiscount(subtotal) {
      const t = elDiscountType.value;
      const v = parseFloat(elDiscountValue.value || "0");
  
      if (t === "percent") return Math.max(0, subtotal - (subtotal * (v / 100)));
      if (t === "amount") return Math.max(0, subtotal - v);
      return subtotal;
    }
  
    function renderCart() {
      elCart.innerHTML = "";
  
      if (!CART.length) {
        elCart.innerHTML = "<p><em>Cart is empty</em></p>";
      } else {
        CART.forEach((l, idx) => {
          const row = document.createElement("div");
          row.className = "jc-cart-row";
  
          const meta = [];
          if (l.meta?.size) meta.push(`Size: ${l.meta.size}oz`);
          if (l.meta?.flavor) meta.push(`Flavor: ${l.meta.flavor}`);
          if (l.meta?.toppings?.length) meta.push(`Toppings: ${l.meta.toppings.map(t=>t.name).join(", ")}`);
  
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
            l.qty = parseFloat(e.target.value || "1");
            renderCart();
          });
  
          elCart.appendChild(row);
        });
      }
  
      const sub = cartSubtotal();
      const tot = applyDiscount(sub);
  
      elSubtotal.textContent = money(sub);
      elTotal.textContent = money(tot);
    }
  
    async function loadData(search = "") {
      elResult.innerHTML = "";
      const path = JC_POS.restPath + "/products" + (search ? `?search=${encodeURIComponent(search)}` : "");
    
      const data = await apiFetch({ path }).catch((err) => {
        console.error("Products load failed:", err);
        return null;
      });
    
      if (!data) {
        elProducts.innerHTML = "<p>Failed to load products.</p>";
        return;
      }
    
      PRODUCTS = data.products || [];
      TOPPINGS = data.toppings || [];
      renderProducts();
    }
  
    async function checkout() {
      if (!CART.length) return;
  
      elResult.innerHTML = "<p>Processing...</p>";
  
      const payload = {
        cart: CART,
        discount_type: elDiscountType.value,
        discount_value: parseFloat(elDiscountValue.value || "0"),
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
        const msg =
          err?.data?.message ||
          err?.message ||
          (typeof err === "string" ? err : "Request failed");
        elResult.innerHTML = `<p class="jc-bad">Error: ${msg}</p>`;
        return;
      }
  
      if (!res.success) {
        elResult.innerHTML = `<p class="jc-bad">Error: ${res.error || "Unknown"}</p>`;
        return;
      }
  
      CART = [];
      renderCart();
  
      const mh = res.mh || {};
      const estado = mh?.mh_status || mh?.mh_status || "";
      elResult.innerHTML = `
        <div class="jc-good">
          <div><strong>Sale OK</strong></div>
          <div>Invoice ID: ${res.invoice_id}</div>
          <div>Ticket #: ${res.ticket_number ?? "-"}</div>
          <div>MH: ${JSON.stringify(mh)}</div>
        </div>
      `;
    }
  
    // Modal events
    document.getElementById("jc-modal-close").addEventListener("click", closeModal);
    elSize.addEventListener("change", updateItemTotal);
    elFlavor.addEventListener("change", updateItemTotal);
    elToppings.addEventListener("change", updateItemTotal);
  
    document.getElementById("jc-add-to-cart").addEventListener("click", () => {
        if (!currentProduct) return;
      
        const sizeOpt = elSize.options[elSize.selectedIndex];
        const variation_id = sizeOpt?.value ? parseInt(sizeOpt.value, 10) : null;
        const sizePrice = parseFloat(sizeOpt?.dataset.price || currentProduct.price || "0");
        const sizeLabel = sizeOpt?.textContent ? sizeOpt.textContent.split(" (")[0] : "Default";
      
        const tops = getSelectedToppings();
        const topsTotal = tops.reduce((s, t) => s + t.price, 0);
      
        const unit_price = Math.round((sizePrice + topsTotal) * 100) / 100;
      
        CART.push({
          product_id: currentProduct.id,
          variation_id: variation_id,
          name: currentProduct.name,
          qty: 1,
          unit_price: unit_price,
          meta: {
            size: sizeLabel,
            flavor: elFlavor.value,
            toppings: tops
          }
        });
      
        closeModal();
        renderCart();
      });
  
    // Toolbar events
    document.getElementById("jc-refresh").addEventListener("click", () => loadData(document.getElementById("jc-search").value.trim()));
    document.getElementById("jc-checkout").addEventListener("click", checkout);
  
    let searchTimer = null;
    document.getElementById("jc-search").addEventListener("input", (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => loadData(e.target.value.trim()), 250);
    });
  
    elDiscountType.addEventListener("change", renderCart);
    elDiscountValue.addEventListener("input", renderCart);
  
    // Boot
    loadData();
    renderCart();
  })();