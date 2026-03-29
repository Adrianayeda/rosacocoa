(() => {
  const STORAGE_KEY = 'rosaCcoaCart';
  const defaultConfig = {
    whatsapp_number: '+52 55 1234 5678'
  };
  let config = { ...defaultConfig };
  let cartData = [];

  function loadCartFromStorage() {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored) {
      try {
        cartData = JSON.parse(stored);
      } catch (e) {
        cartData = [];
      }
    }
  }

  function saveCartToStorage() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(cartData));
  }

  function ensureCartShell() {
    if (!document.getElementById('cartBtn')) {
      const btn = document.createElement('button');
      btn.id = 'cartBtn';
      btn.className =
        'fixed bottom-24 right-6 z-40 bg-white text-[#F8B4C4] px-5 py-3 rounded-full font-bold flex items-center gap-2 shadow-lg';
      btn.innerHTML = `
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
          <path d="M3 3a1 1 0 000 2h1.22l.78 3.12 1.64 6.53A2 2 0 008.58 16H17a2 2 0 001.94-1.53l1.25-5A1 1 0 0019.22 8H6.28L5.9 6.47A2 2 0 004 5H3zm5 15a2 2 0 100 4 2 2 0 000-4zm9 2a2 2 0 11-4 0 2 2 0 014 0z" />
        </svg>
        <span id="cartCount" class="font-bold">0</span>
        <span id="cartBadge" class="badge-count hidden">0</span>
      `;
      document.body.appendChild(btn);
    }

    if (!document.getElementById('cartOverlay')) {
      const overlay = document.createElement('div');
      overlay.id = 'cartOverlay';
      overlay.className = 'cart-overlay';
      document.body.appendChild(overlay);
    }

    if (!document.getElementById('cartSidebar')) {
      const sidebar = document.createElement('div');
      sidebar.id = 'cartSidebar';
      sidebar.className = 'cart-sidebar hidden';
      sidebar.innerHTML = `
        <button id="closeCartBtn" class="absolute top-4 right-4 text-[#5D4E60] hover:bg-[#F8B4C4]/20 p-2 rounded-lg z-50">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <div class="bg-gradient-to-r from-[#F8B4C4] to-[#D4B8E0] text-white p-6 pt-12">
          <h2 class="font-display text-2xl font-bold">🛒 Tu Carrito</h2>
        </div>
        <div id="cartItems" class="flex-1 overflow-y-auto p-6" style="max-height: calc(100vh - 350px)"></div>
        <div class="border-t-2 border-[#D4B8E0]/30 p-6 space-y-4">
          <div>
            <label class="block text-sm font-bold text-[#5D4E60] mb-2">Método de pago:</label>
            <select id="paymentMethod" class="w-full border-2 border-[#D4B8E0] rounded-lg px-4 py-2 text-[#5D4E60] font-medium focus:outline-none focus:border-[#F8B4C4]">
              <option value="">Selecciona un método</option>
              <option value="Transferencia bancaria">💳 Transferencia bancaria</option>
              <option value="Efectivo">💵 Efectivo</option>
              <option value="Tarjeta de crédito">💰 Tarjeta de crédito</option>
            </select>
          </div>
          <div class="space-y-2 pt-4 border-t-2 border-[#D4B8E0]/30">
            <div class="flex justify-between text-[#5D4E60]"><span>Subtotal:</span> <span class="font-bold">$<span id="subtotal">0.00</span></span></div>
            <div class="flex justify-between text-lg font-bold text-[#F8B4C4]"><span>Total:</span> <span>$<span id="cartTotal">0.00</span></span></div>
          </div>
          <button id="whatsappBtn" class="w-full bg-[#25D366] hover:bg-[#20BA5A] text-white font-bold py-3 px-4 rounded-lg transition-colors mt-4 flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
            </svg>
            <span id="whatsappBtnText">Enviar pedido</span>
          </button>
        </div>
      `;
      document.body.appendChild(sidebar);
    }
  }

  function updateCartUI() {
    const cartCount = cartData.reduce((sum, item) => sum + item.quantity, 0);
    const cartCountEl = document.getElementById('cartCount');
    if (cartCountEl) cartCountEl.textContent = cartCount;

    const cartBadge = document.getElementById('cartBadge');
    if (cartBadge) {
      if (cartCount > 0) {
        cartBadge.classList.remove('hidden');
        cartBadge.textContent = cartCount;
      } else {
        cartBadge.classList.add('hidden');
      }
    }

    const cartItemsContainer = document.getElementById('cartItems');
    if (!cartItemsContainer) return;

    if (cartData.length === 0) {
      cartItemsContainer.innerHTML = `
        <div class="text-center text-[#5D4E60]/60 py-12">
          <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
          </svg>
          <p>Tu carrito está vacío</p>
        </div>
      `;
      const whatsappBtn = document.getElementById('whatsappBtn');
      if (whatsappBtn) whatsappBtn.disabled = true;
    } else {
      cartItemsContainer.innerHTML = cartData
        .map(
          (item, index) => `
          <div class="cart-item bg-[#FFF8F0] rounded-lg p-4 mb-4 border-l-4 border-[#F8B4C4]">
            <div class="flex justify-between items-start mb-3">
              <h4 class="font-bold text-[#5D4E60] flex-1 text-sm">${item.product_name}</h4>
              <button onclick="rosaCart.removeFromCart(${index})" class="text-[#F8B4C4] hover:text-[#E8A4B4] ml-2">✖</button>
            </div>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <button onclick="rosaCart.decreaseQuantity(${index})" class="text-[#D4B8E0] hover:bg-[#D4B8E0]/10 px-2 py-1 rounded">−</button>
                <input type="number" class="quantity-input" value="${item.quantity}" data-item-index="${index}" onchange="rosaCart.updateQuantity(this)">
                <button onclick="rosaCart.increaseQuantity(${index})" class="text-[#D4B8E0] hover:bg-[#D4B8E0]/10 px-2 py-1 rounded">+</button>
              </div>
              <div class="text-right">
                <p class="text-xs text-[#5D4E60]/60">c/u: $${item.price.toFixed(2)}</p>
                <p class="font-bold text-[#F8B4C4]">$${(item.price * item.quantity).toFixed(2)}</p>
              </div>
            </div>
          </div>
        `
        )
        .join('');
      const whatsappBtn = document.getElementById('whatsappBtn');
      if (whatsappBtn) whatsappBtn.disabled = false;
    }

    updateTotals();
  }

  function updateTotals() {
    const total = cartData.reduce((sum, item) => sum + item.price * item.quantity, 0);
    const subtotalEl = document.getElementById('subtotal');
    const totalEl = document.getElementById('cartTotal');
    if (subtotalEl) subtotalEl.textContent = total.toFixed(2);
    if (totalEl) totalEl.textContent = total.toFixed(2);
  }

  function addItem(product) {
    const existingItem = cartData.find((item) => item.id === product.id);
    if (existingItem) {
      existingItem.quantity += 1;
    } else {
      cartData.push({
        id: product.id,
        product_name: product.name,
        quantity: 1,
        price: product.price
      });
    }
    saveCartToStorage();
    updateCartUI();
  }

  function removeFromCart(index) {
    cartData.splice(index, 1);
    saveCartToStorage();
    updateCartUI();
  }

  function increaseQuantity(index) {
    if (cartData[index]) {
      cartData[index].quantity += 1;
      saveCartToStorage();
      updateCartUI();
    }
  }

  function decreaseQuantity(index) {
    if (cartData[index] && cartData[index].quantity > 1) {
      cartData[index].quantity -= 1;
      saveCartToStorage();
      updateCartUI();
    }
  }

  function updateQuantity(input) {
    const index = parseInt(input.dataset.itemIndex, 10);
    const newQuantity = parseInt(input.value, 10) || 1;
    if (cartData[index] && newQuantity > 0) {
      cartData[index].quantity = newQuantity;
      saveCartToStorage();
      updateCartUI();
    }
  }

  function setupEventListeners() {
    const cartBtn = document.getElementById('cartBtn');
    const closeCartBtn = document.getElementById('closeCartBtn');
    const cartOverlay = document.getElementById('cartOverlay');
    const cartSidebar = document.getElementById('cartSidebar');
    const whatsappBtn = document.getElementById('whatsappBtn');
    const floatingButtons = document.getElementById('floatingButtons');

    function openCart() {
      if (!cartSidebar || !cartOverlay) return;
      cartSidebar.classList.remove('hidden');
      cartOverlay.classList.add('active');
      if (floatingButtons) floatingButtons.classList.add('hidden');
    }

    function closeCart() {
      if (!cartSidebar || !cartOverlay) return;
      cartSidebar.classList.add('hidden');
      cartOverlay.classList.remove('active');
      if (floatingButtons) floatingButtons.classList.remove('hidden');
    }

    if (cartBtn && cartSidebar && cartOverlay) {
      cartBtn.onclick = () => {
        if (cartSidebar.classList.contains('hidden')) {
          openCart();
        } else {
          closeCart();
        }
      };
    }

    if (closeCartBtn && cartSidebar && cartOverlay) {
      closeCartBtn.onclick = () => {
        closeCart();
      };
    }

    if (cartOverlay && cartSidebar) {
      cartOverlay.onclick = () => {
        closeCart();
      };
    }

    if (whatsappBtn) {
      whatsappBtn.onclick = sendToWhatsApp;
    }
  }

  function sendToWhatsApp() {
    if (cartData.length === 0) return;

    const paymentMethodEl = document.getElementById('paymentMethod');
    const paymentMethod = paymentMethodEl ? paymentMethodEl.value : '';
    if (!paymentMethod) {
      alert('Por favor selecciona un método de pago');
      return;
    }

    const total = cartData.reduce((sum, item) => sum + item.price * item.quantity, 0);
    let message = '🎂 *PEDIDO ROSA COCOA* 🎂\n\n';
    message += '*PRODUCTOS:*\n';

    cartData.forEach((item) => {
      const itemTotal = (item.price * item.quantity).toFixed(2);
      message += `• ${item.product_name}\n`;
      message += `  Cantidad: ${item.quantity} x $${item.price.toFixed(2)}\n`;
      message += `  Subtotal: $${itemTotal}\n\n`;
    });

    message += `*TOTAL: $${total.toFixed(2)}*\n`;
    message += `*Método de pago: ${paymentMethod}*\n\n`;
    message += 'Por favor confirma este pedido. ¡Gracias! 💕';

    const whatsappNumber = (config.whatsapp_number || defaultConfig.whatsapp_number).replace(/\D/g, '');
    const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${encodeURIComponent(message)}`;
    window.open(whatsappUrl, '_blank');
  }

  function init(options = {}) {
    config = { ...config, ...options };
    loadCartFromStorage();
    ensureCartShell();
    setupEventListeners();
    updateCartUI();
  }

  function setConfig(options = {}) {
    config = { ...config, ...options };
  }

  window.rosaCart = {
    init,
    setConfig,
    addItem,
    removeFromCart,
    increaseQuantity,
    decreaseQuantity,
    updateQuantity,
    refresh: updateCartUI,
    getItems: () => [...cartData]
  };
})();
