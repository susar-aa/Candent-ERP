/**
 * Frontend logic for Professional Desktop & Mobile Billing POS
 * Includes Automatic Tiered Promotion Evaluations, Unified Interactive Prompts, and FOC Support.
 */
document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    let productDb = [];
    let activePromotions = [];
    
    // State manager to remember which promos the user accepted/rejected
    let promoState = { applied: {}, rejected: [] }; // Modified: applied is now an Object, rejected is an Array
    let isEvaluatingPromos = false;
    
    // UI Elements (Admin POS)
    const customerSelect = document.getElementById('customerSelect');
    const customerAddress = document.getElementById('customerAddress');
    const btnCustomerProfile = document.getElementById('btnCustomerProfile');
    const customerProfileIframe = document.getElementById('customerProfileIframe');
    
    const productSelect = document.getElementById('productSelect');
    const entryQty = document.getElementById('entryQty');
    const entryRate = document.getElementById('entryRate');
    const entryDis = document.getElementById('entryDis');
    const entryNet = document.getElementById('entryNet');
    const btnAddItem = document.getElementById('btnAddItem');
    const manualFocSwitch = document.getElementById('isManualFoc'); 
    const repSelect = document.getElementById('repSelect'); 
    
    const cartBody = document.getElementById('cartBody');
    const summarySubTotal = document.getElementById('summarySubTotal');
    const summaryDiscount = document.getElementById('summaryDiscount');
    const summaryTax = document.getElementById('summaryTax');
    const summaryNetAmount = document.getElementById('summaryNetAmount');
    
    const summaryOutstanding = document.getElementById('summaryOutstanding');
    const summaryOutRow = document.getElementById('summaryOutRow');
    const summaryTotalPayable = document.getElementById('summaryTotalPayable');

    const payCash = document.getElementById('payCash');
    const payBank = document.getElementById('payBank');
    const payCheque = document.getElementById('payCheque');
    const chequeFields = document.getElementById('chequeFields');

    const btnSaveTop = document.getElementById('btnSaveTop');
    const btnSavePrintTop = document.getElementById('btnSavePrintTop');
    const btnConfirmSale = document.getElementById('btnConfirmSale'); // Mobile specific
    const checkoutMessage = document.getElementById('checkoutMessage');

    // UI Elements (Rep Mobile POS Specific)
    const modalQty = document.getElementById('modalQty');
    const modalPrice = document.getElementById('modalPrice');
    const modalDisType = document.getElementById('modalDisType');
    const modalDisValue = document.getElementById('modalDisValue');
    const modalNetTotal = document.getElementById('modalNetTotal');
    const btnAddToCart = document.getElementById('btnAddToCart');

    let currentSelectedProduct = null;
    let activeProductData = null; // Used for Rep Mobile Modal
    let selectedCustomerData = { id: null, name: null, outstanding: 0 };
    
    let customerTomSelect = null;
    let productTomSelect = null;

    // --- 0. PRE-FETCH PROMOTIONS DATABASE ---
    fetch('../ajax/fetch_promotions.php')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                activePromotions = data.promotions;
                productDb = data.products;
            }
        }).catch(err => console.error("Error loading promotions engine:", err));

    // --- INITIALIZE SEARCHABLE DROPDOWNS (TOM SELECT) ---
    if (customerSelect && typeof TomSelect !== 'undefined') {
        customerTomSelect = new TomSelect(customerSelect, { create: false, sortField: { field: "text", direction: "asc" } });
    }
    if (productSelect && typeof TomSelect !== 'undefined') {
        productTomSelect = new TomSelect(productSelect, { create: false, sortField: { field: "text", direction: "asc" } });
    }

    // --- STOCK LOCATION SELECTOR ---
    const stockSourceSelect = document.getElementById('stockSourceSelect');
    
    async function updateStockSource(isInit = false) {
        if (!stockSourceSelect) return;
        const source = stockSourceSelect.value;
        const repId = repSelect ? repSelect.value : '';
        
        if (!isInit && cart.length > 0) {
            if (!confirm("Changing the stock source will clear the current cart. Do you want to proceed?")) {
                // Revert selection
                stockSourceSelect.value = source === 'warehouse' ? 'vehicle' : 'warehouse';
                return;
            }
            cart = [];
            renderCart();
        }
        
        if (source === 'warehouse') {
            refreshProductTomSelect(source, {});
        } else if (source === 'vehicle') {
            if (!repId) {
                alert("Please select a representative first.");
                stockSourceSelect.value = 'warehouse';
                return;
            }
            try {
                const response = await fetch(`../ajax/get_vehicle_stock.php?rep_id=${repId}`);
                const result = await response.json();
                if (result.success) {
                    const stocks = result.stocks || {};
                    refreshProductTomSelect(source, stocks);
                } else {
                    console.error("Error fetching vehicle stock:", result.error);
                }
            } catch (error) {
                console.error("Fetch vehicle stock error:", error);
            }
        }
    }
    
    function refreshProductTomSelect(source, stocks) {
        if (productSelect) {
            let selectedVal = '';
            
            // 1. Destroy existing TomSelect instance first (restores original HTML to native select)
            if (productSelect.tomselect) {
                selectedVal = productSelect.tomselect.getValue();
                productSelect.tomselect.destroy();
            }
            
            // 2. Update the data-stock attributes on the clean native select options
            Array.from(productSelect.options).forEach(opt => {
                if (source === 'warehouse') {
                    const whStock = opt.getAttribute('data-warehouse-stock');
                    if (whStock !== null) {
                        opt.setAttribute('data-stock', whStock);
                    }
                } else if (source === 'vehicle') {
                    const prodId = opt.value;
                    if (prodId) {
                        const vanStock = stocks[prodId] !== undefined ? stocks[prodId] : 0;
                        opt.setAttribute('data-stock', vanStock);
                    }
                }
            });
            
            // 3. Re-initialize TomSelect
            productTomSelect = new TomSelect('#productSelect', {
                dropdownClass: 'ts-dropdown custom-product-dropdown',
                searchField: ['text'],
                render: {
                    option: function (data, escape) {
                        const opt = productSelect.querySelector('option[value="' + escape(data.value) + '"]');
                        if (!opt || !data.value) return `<div class="p-2 text-muted">${escape(data.text)}</div>`;
                        const name  = opt.getAttribute('data-name')  || data.text;
                        const sku   = opt.getAttribute('data-sku')   || '';
                        const price = opt.getAttribute('data-price')  || '0.00';
                        const stock = parseInt(opt.getAttribute('data-stock') || '0');
                        const sCol  = stock > 10 ? 'var(--accent-dark)' : (stock > 0 ? '#C07000' : '#CC2200');
                        return `
                            <div class="d-flex justify-content-between px-3 py-2" style="border-bottom:1px solid var(--ios-separator);cursor:pointer;">
                                <div style="flex:1;min-width:0;padding-right:12px;">
                                    <div style="font-weight:700;font-size:0.88rem;color:#1c1c1e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escape(name)}</div>
                                    <div style="font-size:0.74rem;color:#8e8e93;margin-top:2px;">SKU: ${escape(sku)}</div>
                                </div>
                                <div style="text-align:right;white-space:nowrap;flex-shrink:0;">
                                    <div style="font-weight:700;font-size:0.88rem;color:var(--accent-dark);">Rs ${escape(price)}</div>
                                    <div style="font-size:0.74rem;font-weight:600;color:${sCol};margin-top:2px;">${stock} Available</div>
                                </div>
                            </div>`;
                    },
                    item: function (data, escape) {
                        const opt = productSelect.querySelector('option[value="' + escape(data.value) + '"]');
                        if (!opt || !data.value) return `<div>${escape(data.text)}</div>`;
                        const name = opt.getAttribute('data-name') || data.text;
                        const sku  = opt.getAttribute('data-sku')  || '';
                        return `<div style="font-weight:700;font-size:0.82rem;color:#1c1c1e;">${escape(name)} <span style="font-weight:500;color:#8e8e93;">(${escape(sku)})</span></div>`;
                    }
                }
            });
            
            if (selectedVal) {
                productSelect.tomselect.setValue(selectedVal);
            }
            productSelect.dispatchEvent(new Event('change'));
        }
    }
    
    if (stockSourceSelect) {
        stockSourceSelect.addEventListener('change', () => updateStockSource(false));
    }
    if (repSelect) {
        repSelect.addEventListener('change', () => {
            if (stockSourceSelect && stockSourceSelect.value === 'vehicle') {
                updateStockSource(false);
            }
        });
    }

    // --- EDIT MODE INITIALIZATION ---
    function initEditMode() {
        if (typeof window.editInvoiceData !== 'undefined' && window.editInvoiceData !== null) {
            const data = window.editInvoiceData;
            if (customerTomSelect && data.customer_id) customerTomSelect.setValue(data.customer_id);
            
            if (summaryDiscount) summaryDiscount.value = data.discount_amount.toFixed(2);
            if (summaryTax) summaryTax.value = data.tax_amount.toFixed(2);
            
            cart = data.cart;
            
            if(payCash) payCash.value = data.paid_cash > 0 ? data.paid_cash : '';
            if(payBank) payBank.value = data.paid_bank > 0 ? data.paid_bank : '';
            if(payCheque) payCheque.value = data.paid_cheque > 0 ? data.paid_cheque : '';

            if (data.paid_cheque > 0 && data.cheque && chequeFields) {
                document.getElementById('chkBank').value = data.cheque.bank;
                document.getElementById('chkNum').value = data.cheque.number;
                document.getElementById('chkDate').value = data.cheque.date;
                chequeFields.classList.remove('d-none');
            }
            
            if (data.stock_source && stockSourceSelect) {
                stockSourceSelect.value = data.stock_source;
                updateStockSource(true);
            }
            
            renderCart();
        } else {
            // Restore Rep Mobile Cart
            const savedCart = localStorage.getItem('fintrix_rep_pos_cart');
            if (savedCart) {
                try {
                    cart = JSON.parse(savedCart);
                    renderCart();
                } catch(e) {}
            }
        }
    }

    // =========================================================================
    // REP MOBILE POS SPECIFIC LOGIC
    // =========================================================================
    
    window.openProductModal = function(product) {
        activeProductData = product;
        let stock = product.remaining_qty !== undefined ? product.remaining_qty : product.stock;
        
        const nameEl = document.getElementById('modalProdName');
        const stockEl = document.getElementById('modalProdStock');
        if(nameEl) nameEl.textContent = product.name;
        if(stockEl) stockEl.textContent = stock;
        
        if(manualFocSwitch) manualFocSwitch.checked = false;
        if(modalPrice) modalPrice.readOnly = false;
        if(modalDisValue) modalDisValue.readOnly = false;
        
        const existing = cart.find(c => c.product_id == product.product_id && !c.promo_id);
        if (existing) {
            if(modalQty) modalQty.value = existing.quantity;
            if(modalPrice) modalPrice.value = parseFloat(existing.sell_price).toFixed(2);
            if(modalDisType) modalDisType.value = existing.dis_type || '%';
            if(modalDisValue) modalDisValue.value = existing.dis_value || 0;
            if(existing.is_foc && manualFocSwitch) {
                manualFocSwitch.checked = true;
                if(modalPrice) modalPrice.readOnly = true;
                if(modalDisValue) modalDisValue.readOnly = true;
            }
        } else {
            if(modalQty) modalQty.value = 1;
            if(modalPrice) modalPrice.value = parseFloat(product.selling_price || product.price).toFixed(2);
            if(modalDisType) modalDisType.value = '%';
            if(modalDisValue) modalDisValue.value = 0;
        }
        
        if(modalQty) modalQty.max = stock;
        calculateModalNet();
        
        const prodModalEl = document.getElementById('productModal');
        if(prodModalEl && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(prodModalEl).show();
        }
    };

    function calculateModalNet() {
        if(!activeProductData || !modalQty) return;
        
        if (manualFocSwitch && manualFocSwitch.checked) {
            if(modalNetTotal) modalNetTotal.textContent = "0.00";
            return;
        }

        const qty = parseFloat(modalQty.value) || 0;
        const price = parseFloat(modalPrice.value) || 0;
        const dType = modalDisType ? modalDisType.value : '%';
        const dVal = modalDisValue ? parseFloat(modalDisValue.value) || 0 : 0;
        
        const gross = qty * price;
        let disAmt = dType === '%' ? gross * (dVal / 100) : dVal * qty;
        
        if(modalNetTotal) modalNetTotal.textContent = (gross - disAmt).toFixed(2);
    }

    if(modalQty) {
        [modalQty, modalPrice, modalDisType, modalDisValue].forEach(el => {
            if(el) el.addEventListener('input', calculateModalNet);
        });
    }

    if(btnAddToCart) {
        btnAddToCart.addEventListener('click', function() {
            if(!activeProductData) return;
            
            const qty = parseInt(modalQty.value) || 0;
            const price = parseFloat(modalPrice.value) || 0;
            const maxStock = activeProductData.remaining_qty !== undefined ? parseInt(activeProductData.remaining_qty) : parseInt(activeProductData.stock);
            const isFoc = manualFocSwitch ? manualFocSwitch.checked : false;

            if (qty <= 0) { alert('Quantity must be greater than 0.'); return; }
            if (qty > maxStock) { alert(`Only ${maxStock} items available in van.`); return; }

            const existingIdx = cart.findIndex(c => c.product_id == activeProductData.product_id && c.is_foc === isFoc && !c.promo_id);
            
            const cartItem = {
                product_id: activeProductData.product_id,
                supplier_id: activeProductData.supplier_id,
                name: activeProductData.name,
                product_name: activeProductData.name,
                sku: activeProductData.sku || 'N/A',
                category_id: activeProductData.category_id,
                sell_price: price,
                quantity: qty,
                dis_type: modalDisType ? modalDisType.value : '%',
                dis_value: modalDisValue ? parseFloat(modalDisValue.value) || 0 : 0,
                dis_percent: modalDisType && modalDisType.value === '%' ? (parseFloat(modalDisValue.value) || 0) : 0,
                max_stock: maxStock,
                is_foc: isFoc,
                promo_id: null
            };

            if (existingIdx > -1) {
                cart[existingIdx] = cartItem;
            } else {
                cart.push(cartItem);
            }

            const prodModalEl = document.getElementById('productModal');
            if(prodModalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getInstance(prodModalEl).hide();
            }

            const prodSearchInput = document.getElementById('prodSearchInput');
            if(prodSearchInput) {
                prodSearchInput.value = '';
                prodSearchInput.dispatchEvent(new Event('input'));
            }

            renderCart();
        });
    }


    // =========================================================================
    // ADMIN POS SPECIFIC LOGIC
    // =========================================================================

    if(customerSelect) {
        customerSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && this.value !== '') {
                selectedCustomerData = { 
                    id: this.value, 
                    name: selectedOption.text,
                    outstanding: parseFloat(selectedOption.dataset.outstanding) || 0
                };
                if(customerAddress) customerAddress.value = selectedOption.dataset.address || '';
                
                if(btnCustomerProfile) {
                    btnCustomerProfile.style.display = 'inline-block';
                    btnCustomerProfile.onclick = () => { customerProfileIframe.src = `view_customer.php?id=${selectedOption.value}&layout=modal`; };
                }
            } else {
                selectedCustomerData = { id: null, name: null, outstanding: 0 };
                if(customerAddress) customerAddress.value = '';
                if(btnCustomerProfile) btnCustomerProfile.style.display = 'none';
            }
            if(summarySubTotal) updateTotals(parseFloat(summarySubTotal.value) || 0);
        });
    }

    if(productSelect) {
        productSelect.addEventListener('change', function() {
            const val = this.value;
            if (!val) { currentSelectedProduct = null; return; }
            
            const option = this.options[this.selectedIndex];
            
            let fallbackCatId = null;
            let pData = productDb.find(p => p.id == val);
            if (pData) fallbackCatId = pData.category_id;

            currentSelectedProduct = {
                id: val,
                name: option.dataset.name,
                sku: option.dataset.sku,
                price: parseFloat(option.dataset.price),
                stock: parseInt(option.dataset.stock),
                supplier: option.dataset.supplier,
                category_id: option.dataset.category || fallbackCatId,
                main_category_id: option.dataset.mainCategory
            };
            
            entryRate.value = currentSelectedProduct.price.toFixed(2);
            entryQty.value = 1;
            entryDis.value = 0;
            
            if(manualFocSwitch) manualFocSwitch.checked = false; 
            calculateEntryNet();
        });
    }

    function calculateEntryNet() {
        if(!entryQty) return;
        const qty = parseFloat(entryQty.value) || 0;
        
        if (manualFocSwitch && manualFocSwitch.checked) {
            entryRate.value = "0.00";
            entryNet.value = "0.00";
            return;
        } else if (currentSelectedProduct && (parseFloat(entryRate.value) === 0 || entryRate.value === "0.00" || entryRate.value === "")) {
            entryRate.value = currentSelectedProduct.price.toFixed(2);
        }

        const rate = parseFloat(entryRate.value) || 0;
        const disPercent = parseFloat(entryDis.value) || 0;
        const gross = qty * rate;
        const discountAmt = gross * (disPercent / 100);
        entryNet.value = (gross - discountAmt).toFixed(2);
    }
    
    if(entryQty) {
        [entryQty, entryRate, entryDis].forEach(input => input.addEventListener('input', calculateEntryNet));
    }
    
    if(manualFocSwitch) {
        manualFocSwitch.addEventListener('change', function() {
            if (this.checked) {
                if(modalPrice) { modalPrice.value = "0.00"; modalPrice.readOnly = true; }
                if(modalDisValue) { modalDisValue.value = "0"; modalDisValue.readOnly = true; }
            } else {
                if (activeProductData && modalPrice) modalPrice.value = parseFloat(activeProductData.selling_price || activeProductData.price).toFixed(2);
                if(modalPrice) modalPrice.readOnly = false;
                if(modalDisValue) modalDisValue.readOnly = false;
            }
            calculateEntryNet();
            calculateModalNet();
        });
    }

    if(btnAddItem) {
        btnAddItem.addEventListener('click', function() {
            if (!currentSelectedProduct) { alert('Please select a valid product.'); return; }
            
            let qty = parseInt(entryQty.value) || 0;
            let rate = parseFloat(entryRate.value) || 0;
            let disPercent = parseFloat(entryDis.value) || 0;
            let isFoc = manualFocSwitch ? manualFocSwitch.checked : false;

            if (qty <= 0) { alert('Quantity must be greater than 0.'); return; }

            const existingItem = cart.find(c => c.product_id == currentSelectedProduct.id && !c.promo_id && c.is_foc === isFoc);
            const currentCartQty = existingItem ? existingItem.quantity : 0;
            
            if ((qty + currentCartQty) > currentSelectedProduct.stock) {
                alert(`Cannot add. Only ${currentSelectedProduct.stock} items available in stock.`);
                return;
            }

            if (existingItem) {
                existingItem.quantity += qty;
                existingItem.sell_price = rate; 
                existingItem.dis_percent = disPercent;
            } else {
                cart.push({
                    product_id: currentSelectedProduct.id,
                    product_name: currentSelectedProduct.name,
                    name: currentSelectedProduct.name,
                    sku: currentSelectedProduct.sku || 'N/A',
                    supplier_id: currentSelectedProduct.supplier,
                    category_id: currentSelectedProduct.category_id,
                    sell_price: rate,
                    quantity: qty,
                    dis_percent: disPercent,
                    dis_type: '%',
                    dis_value: disPercent,
                    max_stock: currentSelectedProduct.stock,
                    is_foc: isFoc,
                    promo_id: null,
                    main_category_id: currentSelectedProduct.main_category_id
                });
            }

            if(productTomSelect) productTomSelect.clear();
            entryQty.value = 1;
            entryRate.value = '';
            entryDis.value = 0;
            entryNet.value = '';
            if(manualFocSwitch) manualFocSwitch.checked = false;
            currentSelectedProduct = null;
            
            renderCart();
        });
    }

    // Helper: Remove Promo
    function removePromoFromCart(promoIdNum) {
        cart = cart.filter(item => !(item.is_foc && item.promo_id == promoIdNum));
        cart.forEach(item => {
            if (item.promo_id == promoIdNum) {
                item.dis_type = '%'; item.dis_value = 0; item.dis_percent = 0; item.promo_id = null;
            }
        });
    }

    // =========================================================================
    // SMART PROMOTIONS ENGINE EVALUATOR - UNIFIED PROMPT & TIERS UPGRADE
    // =========================================================================
    function evaluatePromotions() {
        if (isEvaluatingPromos || activePromotions.length === 0) return;
        isEvaluatingPromos = true;
        let cartChanged = false;

        let catQty = {}; let catAmt = {};
        let prodQty = {}; let prodAmt = {};
        let mainCatQty = {}; let mainCatAmt = {};

        // Group actual billed quantities/amounts (Ignore FOC items to prevent feedback loops)
        cart.forEach(item => {
            if (item.is_foc) return; 

            let cid = parseInt(item.category_id);
            let pid = parseInt(item.product_id);
            let mcid = parseInt(item.main_category_id);
            let qty = parseInt(item.quantity);
            let amt = (item.sell_price * qty) - (item.dis_type === '%' ? (item.sell_price * qty * (item.dis_value/100)) : (item.dis_value * qty));

            if(cid) { catQty[cid] = (catQty[cid] || 0) + qty; catAmt[cid] = (catAmt[cid] || 0) + amt; }
            if(mcid) { mainCatQty[mcid] = (mainCatQty[mcid] || 0) + qty; mainCatAmt[mcid] = (mainCatAmt[mcid] || 0) + amt; }
            prodQty[pid] = (prodQty[pid] || 0) + qty; prodAmt[pid] = (prodAmt[pid] || 0) + amt;
        });

        let newlyTriggered = [];
        let upcomingPromos = [];

        for (const promo of activePromotions) {
            let tiers = [];
            try { tiers = JSON.parse(promo.tiers_config); } catch(e) {}
            if (!tiers || tiers.length === 0) continue;

            let promoIdNum = parseInt(promo.id);
            let currentAmt = 0; let currentQty = 0;

            if (promo.target_main_category_id && parseInt(promo.target_main_category_id) > 0) {
                currentAmt = mainCatAmt[parseInt(promo.target_main_category_id)] || 0;
                currentQty = mainCatQty[parseInt(promo.target_main_category_id)] || 0;
            } else if (promo.target_category_id) {
                currentAmt = catAmt[parseInt(promo.target_category_id)] || 0;
                currentQty = catQty[parseInt(promo.target_category_id)] || 0;
            } else if (promo.target_product_id) {
                currentAmt = prodAmt[parseInt(promo.target_product_id)] || 0;
                currentQty = prodQty[parseInt(promo.target_product_id)] || 0;
            }

            let highestTierIndex = -1;
            let highestTier = null;

            // Find highest eligible tier
            for (let i = 0; i < tiers.length; i++) {
                let t = tiers[i];
                if (promo.promo_type === 'foc' && currentQty >= parseInt(t.min_qty)) {
                    if (highestTierIndex === -1 || parseInt(t.min_qty) > parseInt(highestTier.min_qty)) {
                        highestTierIndex = i; highestTier = t;
                    }
                } else if (promo.promo_type === 'percentage' && currentAmt >= parseFloat(t.min_amount)) {
                    if (highestTierIndex === -1 || parseFloat(t.min_amount) > parseFloat(highestTier.min_amount)) {
                        highestTierIndex = i; highestTier = t;
                    }
                }
            }

            // Track upcoming tier for suggestion
            if (highestTierIndex < tiers.length - 1) {
                let nextTier = tiers[highestTierIndex + 1];
                let rem = 0; let unit = '';
                if (promo.promo_type === 'foc') {
                    rem = parseInt(nextTier.min_qty) - currentQty;
                    unit = 'units';
                } else {
                    rem = parseFloat(nextTier.min_amount) - currentAmt;
                    unit = 'Rs';
                }
                
                if (rem > 0) {
                    upcomingPromos.push({
                        name: promo.name,
                        remaining: rem,
                        unit: unit,
                        benefit: nextTier.discount_percent ? nextTier.discount_percent + '%' : nextTier.free_qty + 'x Free'
                    });
                }
            }

            if (highestTierIndex !== -1) {
                let currentAppliedTier = promoState.applied[promoIdNum];
                
                if (currentAppliedTier !== highestTierIndex && !promoState.rejected.includes(promoIdNum + '_' + highestTierIndex)) {
                    newlyTriggered.push({
                        promo_id: promoIdNum,
                        promo_type: promo.promo_type,
                        tier_index: highestTierIndex,
                        tier: highestTier,
                        target_category_id: promo.target_category_id,
                        target_product_id: promo.target_product_id
                    });
                }
            } else {
                // Dropped below all thresholds
                if (promoState.applied[promoIdNum] !== undefined) {
                    removePromoFromCart(promoIdNum);
                    delete promoState.applied[promoIdNum];
                    cartChanged = true;
                }
            }
        }

        // --- Render Suggestions ---
        const suggestionBar = document.getElementById('promoSuggestionBar');
        const suggestionList = document.getElementById('promoSuggestionsList');
        if (suggestionBar && suggestionList) {
            if (upcomingPromos.length > 0) {
                suggestionBar.classList.remove('d-none');
                suggestionList.innerHTML = upcomingPromos.map(up => `
                    <div style="background:#fff; padding:8px 12px; border-radius:10px; border:1px solid #e0e7ff; font-size:0.75rem;">
                        <span class="text-muted">Reach ${up.unit} ${up.unit === 'Rs' ? up.remaining.toFixed(2) : up.remaining} more in </span>
                        <strong class="text-dark">${up.name}</strong>
                        <span class="text-muted"> to get </span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">${up.benefit}</span>
                    </div>
                `).join('');
            } else {
                suggestionBar.classList.add('d-none');
            }
        }

        // --- ONE Unified Prompt for ALL newly triggered tiers! ---
        if (newlyTriggered.length > 0) {
            let msg = "🔥 PROMOTIONS TRIGGERED!\n\nThis order reached the tier for:\n";
            let aggregatedFoc = {};
            let pctMsgs = [];

            newlyTriggered.forEach(nt => {
                if (nt.promo_type === 'foc') {
                    let pid = nt.tier.free_product_id;
                    if(!aggregatedFoc[pid]) aggregatedFoc[pid] = 0;
                    aggregatedFoc[pid] += parseInt(nt.tier.free_qty);
                } else {
                    pctMsgs.push(`${parseFloat(nt.tier.discount_percent)}% Value Discount`);
                }
            });

            let validPrompt = false;
            for(let pid in aggregatedFoc) {
                let freeProd = productDb.find(p => p.id == pid);
                if(freeProd) {
                    msg += `- ${aggregatedFoc[pid]}x ${freeProd.name} for FREE\n`;
                    validPrompt = true;
                }
            }
            if (pctMsgs.length > 0) {
                pctMsgs.forEach(m => { msg += `- ${m}\n`; validPrompt = true; });
            }

            if (validPrompt) {
                msg += "\nDo you want to apply these to the bill?";
                
                if (confirm(msg)) {
                    newlyTriggered.forEach(nt => {
                        // Crucial: Remove the old tier for THIS promo before applying the new one
                        if (promoState.applied[nt.promo_id] !== undefined) {
                            removePromoFromCart(nt.promo_id);
                        }

                        // Apply new tier
                        if (nt.promo_type === 'foc') {
                            let freeProd = productDb.find(p => p.id == nt.tier.free_product_id);
                            if (freeProd) {
                                cart.push({
                                    product_id: freeProd.id,
                                    supplier_id: null,
                                    name: freeProd.name,
                                    product_name: freeProd.name,
                                    sku: 'FOC-PROMO',
                                    sell_price: 0,
                                    quantity: parseInt(nt.tier.free_qty),
                                    dis_type: '%',
                                    dis_value: 0,
                                    dis_percent: 0,
                                    max_stock: 9999, // Safely assume system can provide promo stock
                                    is_foc: true,
                                    promo_id: nt.promo_id,
                                    category_id: freeProd.category_id
                                });
                            }
                        } else if (nt.promo_type === 'percentage') {
                            cart.forEach(item => {
                                if (!item.is_foc) {
                                    if ((nt.target_main_category_id && item.main_category_id == nt.target_main_category_id) || 
                                        (nt.target_category_id && item.category_id == nt.target_category_id) || 
                                        (nt.target_product_id && item.product_id == nt.target_product_id)) {
                                        item.dis_type = '%';
                                        item.dis_value = parseFloat(nt.tier.discount_percent);
                                        item.dis_percent = parseFloat(nt.tier.discount_percent);
                                        item.promo_id = nt.promo_id;
                                    }
                                }
                            });
                        }
                        promoState.applied[nt.promo_id] = nt.tier_index;
                    });
                    cartChanged = true;
                } else {
                    newlyTriggered.forEach(nt => {
                        promoState.rejected.push(nt.promo_id + '_' + nt.tier_index);
                    });
                }
            }
        }

        isEvaluatingPromos = false;
        
        if (cartChanged) {
            if (window.editInvoiceData === null) {
                localStorage.setItem('fintrix_rep_pos_cart', JSON.stringify(cart));
            }
            renderCart(); 
        }
    }

    // 5. Render Cart Table (Admin & Rep Unified)
    function renderCart() {
        if(cartBody) cartBody.innerHTML = '';
        const previewBox = document.getElementById('cartItemsPreview'); // Rep App
        if(previewBox) previewBox.innerHTML = '';

        let subtotal = 0;
        let totalItems = 0;

        if (cart.length === 0) {
            if(cartBody) cartBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-cart fs-1 d-block mb-2"></i>Cart is currently empty. Add products from the entry row.</td></tr>';
            if(previewBox) {
                const cartBtn = document.getElementById('mainCheckoutBtn');
                if(cartBtn) cartBtn.classList.add('d-none');
            }
            if(summarySubTotal) updateTotals(0);
            else calculateFinalTotals(0);
            return;
        }

        cart.forEach((item, index) => {
            totalItems++;
            if (item.quantity > item.max_stock && !item.promo_id) item.quantity = item.max_stock;
            if (item.quantity < 1) item.quantity = 1;
            
            const gross = item.sell_price * item.quantity;
            let discountAmt = 0;
            let displayDis = '';

            if(item.dis_type !== undefined) {
                discountAmt = item.dis_type === '%' ? gross * (item.dis_value / 100) : (item.dis_value * item.quantity);
                displayDis = item.dis_value > 0 ? (item.dis_type === '%' ? item.dis_value+'%' : 'Rs '+item.dis_value) : '0';
            } else {
                discountAmt = gross * ((item.dis_percent || 0) / 100);
                displayDis = item.dis_percent || 0;
            }

            const lineTotal = gross - discountAmt;
            subtotal += lineTotal;

            // Admin View Render
            if(cartBody) {
                const tr = document.createElement('tr');
                if (item.is_foc) tr.classList.add('table-success', 'bg-opacity-25'); 
                
                tr.innerHTML = `
                    <td class="ps-3">${index + 1}</td>
                    <td>${item.sku}</td>
                    <td class="fw-bold text-dark">
                        ${item.product_name || item.name} 
                        ${item.is_foc ? '<span class="badge bg-danger ms-1">FOC</span>' : ''}
                        ${item.promo_id ? '<span class="badge bg-primary ms-1" title="Automated Promo Rule"><i class="bi bi-magic"></i> Tier</span>' : ''}
                    </td>
                    <td class="text-center">
                        <input type="number" class="table-input cart-update" data-index="${index}" data-field="quantity" value="${item.quantity}" min="1" max="${item.max_stock}" style="text-align: center;" ${item.promo_id && item.is_foc ? 'readonly' : ''}>
                    </td>
                    <td class="text-end">
                        <input type="number" class="table-input cart-update" data-index="${index}" data-field="sell_price" value="${parseFloat(item.sell_price).toFixed(2)}" step="0.01" ${item.is_foc ? 'readonly' : ''}>
                    </td>
                    <td class="text-center text-danger fw-bold">${displayDis}</td>
                    <td class="text-end fw-bold">${lineTotal.toFixed(2)}</td>
                    <td class="text-center pe-3">
                        <button class="btn btn-sm text-danger remove-btn p-0" data-index="${index}"><i class="bi bi-x-square-fill fs-5"></i></button>
                    </td>
                `;
                cartBody.appendChild(tr);
            }

            // Rep View Render
            if(previewBox) {
                previewBox.innerHTML += `
                    <div class="cart-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.9rem;">
                                ${item.name || item.product_name}
                                ${item.is_foc ? '<span class="badge bg-danger ms-1 px-1">FOC</span>' : ''}
                                ${item.promo_id ? '<span class="badge bg-primary ms-1" title="Promo Rule"><i class="bi bi-magic"></i> Tier</span>' : ''}
                            </div>
                            <div class="text-muted" style="font-size: 0.75rem;">${item.quantity}x @ Rs ${parseFloat(item.sell_price).toFixed(2)} ${item.dis_value > 0 ? `(-${item.dis_value}${item.dis_type} dis)` : ''}</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success small">Rs ${lineTotal.toFixed(2)}</div>
                            <i class="bi bi-trash text-danger" style="cursor:pointer;" onclick="removeCartItem(${index})"></i>
                        </div>
                    </div>
                `;
            }
        });

        // Attach listeners
        document.querySelectorAll('.cart-update').forEach(input => {
            input.addEventListener('change', function() {
                const idx = parseInt(this.dataset.index);
                const field = this.dataset.field;
                const val = parseFloat(this.value);
                if (!isNaN(val)) {
                    cart[idx][field] = val;
                    if(cart[idx].dis_type === undefined) cart[idx].dis_percent = val;
                    renderCart(); 
                }
            });
        });

        document.querySelectorAll('.remove-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                cart.splice(parseInt(this.dataset.index), 1);
                renderCart();
            });
        });

        // Rep specific badges update
        if(document.getElementById('cartItemCount') && document.getElementById('cartTotalBtn')) {
            const cartBtn = document.getElementById('mainCheckoutBtn');
            const countBadge = document.getElementById('cartItemCount');
            const totalBtnText = document.getElementById('cartTotalBtn');
            if (totalItems > 0) {
                cartBtn.classList.remove('d-none');
                countBadge.textContent = totalItems;
                totalBtnText.textContent = subtotal.toFixed(2);
            } else {
                cartBtn.classList.add('d-none');
                const bsOffcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('checkoutCanvas'));
                if (bsOffcanvas) bsOffcanvas.hide();
            }
        }

        // --- Run Engine AFTER rendering basic rows ---
        evaluatePromotions();

        if(summarySubTotal) updateTotals(subtotal);
        else calculateFinalTotals(subtotal); 
    }

    // 6. Update Summary Box & Advanced Balances
    function updateTotals(subtotal) {
        if(!summarySubTotal) return;
        summarySubTotal.value = subtotal.toFixed(2);
        
        let billDiscount = parseFloat(summaryDiscount.value) || 0;
        let tax = parseFloat(summaryTax.value) || 0;
        
        // Calculate promo discount from cart
        let totalPromoDiscount = 0;
        cart.forEach(item => {
            if (item.promo_id && !item.is_foc && item.dis_type === '%') {
                 totalPromoDiscount += (item.sell_price * item.quantity) * (item.dis_value / 100);
            }
        });

        const promoRow = document.getElementById('summaryPromoRow');
        const promoInput = document.getElementById('summaryPromo');
        if (promoRow && promoInput) {
            if (totalPromoDiscount > 0) {
                promoRow.classList.remove('d-none');
                promoInput.value = totalPromoDiscount.toFixed(2);
            } else {
                promoRow.classList.add('d-none');
                promoInput.value = "0.00";
            }
        }

        if (billDiscount > subtotal) {
            billDiscount = subtotal;
            summaryDiscount.value = billDiscount.toFixed(2);
        }

        const netAmount = (subtotal - billDiscount) + tax;
        summaryNetAmount.value = netAmount.toFixed(2);

        const outstanding = selectedCustomerData.outstanding || 0;
        if(summaryOutRow) {
            if (outstanding > 0) {
                summaryOutRow.classList.remove('d-none');
                summaryOutstanding.value = '+ ' + outstanding.toFixed(2);
            } else {
                summaryOutRow.classList.add('d-none');
            }
        }

        const totalPayable = netAmount + outstanding;
        if(summaryTotalPayable) summaryTotalPayable.value = totalPayable.toFixed(2);
        window.totalPayable = totalPayable; 

        calculatePaymentBalance();
    }

    function calculateFinalTotals(subtotal) {
        const sumSubtotalRep = document.getElementById('sumSubtotal');
        if(!sumSubtotalRep) return;

        sumSubtotalRep.textContent = subtotal.toFixed(2);
        
        const billDisType = document.getElementById('billDisType');
        const billDisValue = document.getElementById('billDisValue');
        const taxDisType = document.getElementById('taxDisType');
        const taxDisValue = document.getElementById('taxDisValue');
        const sumDisRow = document.getElementById('sumDisRow');
        const sumDiscount = document.getElementById('sumDiscount');
        const sumNet = document.getElementById('sumNet');
        const sumOutRow = document.getElementById('sumOutRow');
        const sumOutstanding = document.getElementById('sumOutstanding');
        const sumTotalPayable = document.getElementById('sumTotalPayable');

        const bDType = billDisType ? billDisType.value : '%';
        const bDVal = billDisValue ? parseFloat(billDisValue.value) || 0 : 0;
        const tType = taxDisType ? taxDisType.value : '%';
        const tVal = taxDisValue ? parseFloat(taxDisValue.value) || 0 : 0;

        let finalBillDiscount = bDType === '%' ? subtotal * (bDVal / 100) : bDVal;
        if (finalBillDiscount > subtotal) finalBillDiscount = subtotal;
        
        let discountedSubtotal = subtotal - finalBillDiscount;
        let finalTax = tType === '%' ? discountedSubtotal * (tVal / 100) : tVal;

        if (finalBillDiscount > 0) {
            if(sumDisRow) sumDisRow.classList.remove('d-none');
            if(sumDiscount) sumDiscount.textContent = finalBillDiscount.toFixed(2);
        } else {
            if(sumDisRow) sumDisRow.classList.add('d-none');
        }

        const netAmount = discountedSubtotal + finalTax;
        if(sumNet) sumNet.textContent = netAmount.toFixed(2);
        
        const outstanding = parseFloat(selectedCustomerData.outstanding) || 0;
        if(sumOutstanding) sumOutstanding.textContent = outstanding.toFixed(2);
        if (outstanding > 0) {
            if(sumOutRow) sumOutRow.classList.remove('d-none');
        } else {
            if(sumOutRow) sumOutRow.classList.add('d-none');
        }

        const totalPayable = netAmount + outstanding;
        if(sumTotalPayable) sumTotalPayable.textContent = totalPayable.toFixed(2);
        
        window.absoluteBillDiscount = finalBillDiscount; 
        window.absoluteTaxAmount = finalTax;
        window.totalPayable = totalPayable; 

        calculatePaymentBalance();
    }

    function calculatePaymentBalance() {
        const cash = parseFloat(payCash.value) || 0;
        const bank = parseFloat(payBank.value) || 0;
        const cheque = parseFloat(payCheque.value) || 0;

        const totalPaid = cash + bank + cheque;
        const net = window.totalPayable || 0; 
        const balance = totalPaid - net;

        const balanceEl = document.getElementById('paymentBalance');
        const balanceLabel = document.getElementById('paymentBalanceLabel');

        if (balance >= 0) {
            balanceLabel.textContent = "Change Due";
            balanceEl.textContent = balance.toFixed(2);
            balanceEl.className = "fw-bold fs-5 text-dark";
        } else {
            balanceLabel.textContent = "Remaining Due (Ows)";
            balanceEl.textContent = Math.abs(balance).toFixed(2);
            balanceEl.className = "fw-bold fs-5 text-danger";
        }

        if (cheque > 0) {
            if(chequeFields) chequeFields.classList.remove('d-none');
        } else {
            if(chequeFields) chequeFields.classList.add('d-none');
        }
    }

    if(payCash) [payCash, payBank, payCheque].forEach(el => el.addEventListener('input', calculatePaymentBalance));

    // 7. Process Final Save
    async function processOrder(shouldPrint) {
        if (cart.length === 0) { alert("Cart is empty. Add items before saving."); return; }

        const chequeAmt = parseFloat(payCheque ? payCheque.value : 0) || 0;
        if (chequeAmt > 0) {
            if(!document.getElementById('chkBank').value || !document.getElementById('chkNum').value) {
                alert("Please fill in the Cheque details (Bank, Number).");
                return;
            }
        }

        let btnS = btnSaveTop;
        let btnP = btnSavePrintTop;
        let msgBox = checkoutMessage;
        
        if(btnConfirmSale) {
            btnS = btnConfirmSale;
            btnP = btnConfirmSale;
        }

        const originalBtnSaveText = btnS.innerHTML;
        const originalBtnPrintText = btnP ? btnP.innerHTML : '';
        
        btnS.disabled = true;
        if(btnP) btnP.disabled = true;
        
        btnS.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
        if(msgBox) msgBox.innerHTML = '';

        const finalCart = cart.map(item => {
            const gross = item.sell_price * item.quantity;
            let flatDiscount = 0;
            if(item.dis_type !== undefined) {
                flatDiscount = item.dis_type === '%' ? gross * (item.dis_value / 100) : (item.dis_value * item.quantity);
            } else {
                flatDiscount = gross * ((item.dis_percent || 0) / 100);
            }
            return {
                product_id: item.product_id,
                supplier_id: item.supplier_id,
                sell_price: item.sell_price,
                quantity: item.quantity,
                discount: flatDiscount,
                is_foc: item.is_foc ? 1 : 0,
                promo_id: item.promo_id || null 
            };
        });

        const isEditing = typeof window.editInvoiceData !== 'undefined' && window.editInvoiceData !== null;
        let bDis = summaryDiscount ? parseFloat(summaryDiscount.value) : window.absoluteBillDiscount;
        let tAmt = summaryTax ? parseFloat(summaryTax.value) : window.absoluteTaxAmount;

        const urlParams = new URLSearchParams(window.location.search);
        let assignmentIdOverride = urlParams.get('assignment_id');

        const stockSource = stockSourceSelect ? stockSourceSelect.value : 'warehouse';

        const payload = {
            edit_order_id: isEditing ? window.editInvoiceData.order_id : null,
            assignment_id: assignmentIdOverride || null,
            rep_id: repSelect ? repSelect.value : null,
            tiered_stock: stockSourceSelect ? false : (!!repSelect),
            is_general: stockSourceSelect ? (stockSource === 'warehouse') : false,
            customer_id: selectedCustomerData.id || (customerSelect ? customerSelect.value : null),
            bill_discount: bDis || 0,
            tax_amount: tAmt || 0,
            paid_cash: parseFloat(payCash ? payCash.value : 0) || 0,
            paid_bank: parseFloat(payBank ? payBank.value : 0) || 0,
            paid_cheque: chequeAmt,
            cheque_bank: document.getElementById('chkBank') ? document.getElementById('chkBank').value : '',
            cheque_number: document.getElementById('chkNum') ? document.getElementById('chkNum').value : '',
            cheque_date: document.getElementById('chkDate') ? document.getElementById('chkDate').value : '',
            cart: finalCart
        };

        try {
            const response = await fetch('../ajax/process_checkout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            const rawText = await response.text();
            
            let result;
            try {
                result = JSON.parse(rawText);
            } catch(e) {
                console.error("Server Outputted Non-JSON:", rawText);
                if(msgBox) msgBox.innerHTML = `<div class="alert alert-danger p-2">Server Error. Check console logs.</div>`;
                btnS.disabled = false; if(btnP) btnP.disabled = false;
                btnS.innerHTML = originalBtnSaveText;
                return;
            }

            if (result.success) {
                const bsOffcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('checkoutCanvas'));
                if(bsOffcanvas) bsOffcanvas.hide();

                localStorage.removeItem('fintrix_rep_pos_cart');

                finalOrderId = result.order_id;
                if(document.getElementById('successOrderId')) {
                    document.getElementById('successOrderId').textContent = '#' + String(finalOrderId).padStart(6, '0');
                    
                    const btnEmail = document.getElementById('btnEmailReceipt');
                    if (selectedCustomerData.email && selectedCustomerData.email !== '') {
                        btnEmail.style.display = 'block';
                        btnEmail.innerHTML = '<i class="bi bi-envelope"></i> Email Receipt';
                        btnEmail.disabled = false;
                        btnEmail.classList.remove('btn-primary');
                        btnEmail.classList.add('btn-outline-primary');
                    } else {
                        btnEmail.style.display = 'none';
                    }

                    new bootstrap.Modal(document.getElementById('successModal')).show();
                } else {
                    if (shouldPrint && typeof window.open === 'function') {
                        window.open(`view_invoice.php?id=${result.order_id}`, '_blank');
                    }
                    setTimeout(() => { 
                        if(isEditing) window.location.href = 'orders_list.php';
                        else location.reload(); 
                    }, 1000);
                }
            } else {
                if(msgBox) msgBox.innerHTML = `<div class="alert alert-danger p-2">${result.message}</div>`;
                btnS.disabled = false; if(btnP) btnP.disabled = false;
                btnS.innerHTML = originalBtnSaveText;
            }
        } catch (error) {
            console.error("Fetch Error:", error);
            if(msgBox) msgBox.innerHTML = `<div class="alert alert-danger p-2">Network Error. Check connection.</div>`;
            btnS.disabled = false; if(btnP) btnP.disabled = false;
            btnS.innerHTML = originalBtnSaveText;
        }
    }

    if(btnSaveTop) {
        btnSaveTop.addEventListener('click', function(e) { e.preventDefault(); processOrder(false); });
        btnSavePrintTop.addEventListener('click', function(e) { e.preventDefault(); processOrder(true); });
    }
    if(btnConfirmSale) {
        btnConfirmSale.addEventListener('click', function(e) { e.preventDefault(); processOrder(false); });
    }

    // Success Modal Logic
    const btnPrintInvoice = document.getElementById('btnPrintInvoice');
    if (btnPrintInvoice) {
        btnPrintInvoice.addEventListener('click', function() {
            if(window.finalOrderId) window.open(`../pages/view_invoice.php?id=${window.finalOrderId}`, '_blank');
        });
    }

    const btnShowQR = document.getElementById('btnShowQR');
    if (btnShowQR) {
        btnShowQR.addEventListener('click', function() {
            if(window.finalOrderId) {
                const container = document.getElementById('qrContainer');
                const qrcodeEl = document.getElementById('qrcode');
                qrcodeEl.innerHTML = ''; 
                const invoiceUrl = window.location.origin + window.location.pathname.replace('/rep/create_order.php', '') + `/pages/view_invoice.php?id=${window.finalOrderId}`;
                new QRCode(qrcodeEl, { text: invoiceUrl, width: 200, height: 200, colorDark : "#000000", colorLight : "#ffffff", correctLevel : QRCode.CorrectLevel.H });
                container.classList.remove('d-none');
                this.classList.add('d-none'); 
            }
        });
    }

    const btnEmailReceipt = document.getElementById('btnEmailReceipt');
    if (btnEmailReceipt) {
        btnEmailReceipt.addEventListener('click', async function() {
            if(!window.finalOrderId) return;
            const btn = this;
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';

            try {
                const response = await fetch('../ajax/send_receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `order_id=${window.finalOrderId}` });
                const result = await response.json();
                if(result.success) {
                    btn.innerHTML = '<i class="bi bi-check-circle"></i> Sent!';
                    btn.classList.replace('btn-outline-primary', 'btn-primary');
                } else {
                    alert('Error: ' + result.error);
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            } catch(e) {
                alert('Network or Server Error.');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        });
    }

    // Initialize
    initEditMode();
});