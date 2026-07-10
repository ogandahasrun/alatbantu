// Modal Management Helper Functions
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

// Close modal when clicking outside of it
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.style.display = 'none';
    }
});

// Toast notification function
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.padding = '12px 24px';
    toast.style.borderRadius = '8px';
    toast.style.color = '#fff';
    toast.style.fontWeight = '600';
    toast.style.fontSize = '14px';
    toast.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.1)';
    toast.style.zIndex = '9999';
    toast.style.transition = 'all 0.3s ease';
    toast.style.transform = 'translateY(-20px)';
    toast.style.opacity = '0';

    if (type === 'success') {
        toast.style.background = '#10b981';
    } else if (type === 'danger') {
        toast.style.background = '#ef4444';
    } else if (type === 'warning') {
        toast.style.background = '#f59e0b';
    }

    toast.innerText = message;
    document.body.appendChild(toast);

    // Trigger animation
    setTimeout(() => {
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';
    }, 50);

    // Remove toast after 3 seconds
    setTimeout(() => {
        toast.style.transform = 'translateY(-20px)';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Kasir & Faktur Dynamic Rows Builder
class TransactionForm {
    constructor(formId, isPurchase = false) {
        this.form = document.getElementById(formId);
        if (!this.form) return;

        this.isPurchase = isPurchase;
        this.items = []; // Array of {kode_barang, nama_barang, qty, harga, satuan}
        this.suggestions = [];

        this.init();
    }

    init() {
        this.barcodeInput = this.form.querySelector('.tx-search-input');
        this.suggestionsBox = this.form.querySelector('.autocomplete-suggestions');
        this.itemsTableBody = this.form.querySelector('.tx-items-table tbody');
        this.grandTotalEl = this.form.querySelector('.tx-total-val');
        this.grandTotalInput = this.form.querySelector('.tx-total-input');
        
        // Listener for location change - clear basket
        this.lokSelect = this.form.querySelector('select[name="lokasi_id"]');
        if (this.lokSelect) {
            this.lokSelect.addEventListener('change', () => {
                this.items = [];
                this.renderItems();
                showToast("Daftar barang di-reset karena lokasi diubah.", "warning");
            });
        }
        
        // Listeners for credit terms
        this.payTypeSelect = this.form.querySelector('select[name="tipe_pembayaran"]');
        this.creditFields = this.form.querySelector('.credit-fields');

        if (this.payTypeSelect && this.creditFields) {
            this.payTypeSelect.addEventListener('change', () => this.toggleCreditFields());
            this.toggleCreditFields();
        }

        // Input barcode / search term event
        if (this.barcodeInput) {
            this.barcodeInput.addEventListener('input', (e) => this.fetchSuggestions(e.target.value));
            this.barcodeInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.handleEnterKey();
                }
            });
        }

        // Form submit
        this.form.addEventListener('submit', (e) => this.handleSubmit(e));
    }

    toggleCreditFields() {
        if (this.payTypeSelect.value === 'kredit') {
            this.creditFields.style.display = 'block';
            this.form.querySelector('select[name="pelanggan_id"]').required = true;
            this.form.querySelector('select[name="tempo_tipe"]').required = true;
            this.form.querySelector('input[name="jatuh_tempo"]').required = true;
        } else {
            this.creditFields.style.display = 'none';
            this.form.querySelector('select[name="pelanggan_id"]').required = false;
            this.form.querySelector('select[name="tempo_tipe"]').required = false;
            this.form.querySelector('input[name="jatuh_tempo"]').required = false;
        }
    }

    async fetchSuggestions(query) {
        if (!query || query.length < 1) {
            this.suggestionsBox.innerHTML = '';
            this.suggestionsBox.style.display = 'none';
            return;
        }

        try {
            const lokId = this.lokSelect ? this.lokSelect.value : (this.isPurchase ? 1 : 2);
            const url = `index.php?ajax_search_barang=1&q=${encodeURIComponent(query)}&lokasi_id=${lokId}&is_purchase=${this.isPurchase ? 1 : 0}`;
            const res = await fetch(url);
            const data = await res.json();
            
            this.suggestions = data;
            this.renderSuggestions();
        } catch (error) {
            console.error('Error fetching suggestions:', error);
        }
    }

    renderSuggestions() {
        this.suggestionsBox.innerHTML = '';
        if (this.suggestions.length === 0) {
            this.suggestionsBox.style.display = 'none';
            return;
        }

        this.suggestions.forEach(item => {
            const div = document.createElement('div');
            div.className = 'suggestion-item';
            
            const stokInfo = this.isPurchase ? '' : ` | Stok: ${item.stok}`;
            const harga = parseFloat(this.isPurchase ? item.harga_beli : item.harga_jual) || 0;
            const formatHarga = new Intl.NumberFormat('id-ID').format(harga);
            
            div.innerHTML = `
                <div>
                    <strong>${item.kode_barang}</strong> - ${item.nama_barang} ${stokInfo}
                </div>
                <div>Rp ${formatHarga} (${item.satuan})</div>
            `;
            
            div.addEventListener('click', () => {
                this.addItem(item);
                this.barcodeInput.value = '';
                this.suggestionsBox.innerHTML = '';
                this.suggestionsBox.style.display = 'none';
            });

            this.suggestionsBox.appendChild(div);
        });

        this.suggestionsBox.style.display = 'block';
    }

    handleEnterKey() {
        // If there's exactly 1 suggestion, select it
        if (this.suggestions.length === 1) {
            this.addItem(this.suggestions[0]);
            this.barcodeInput.value = '';
            this.suggestionsBox.innerHTML = '';
            this.suggestionsBox.style.display = 'none';
        } else if (this.barcodeInput.value.trim() !== '') {
            // Treat input as barcode search
            this.searchAndAddByBarcode(this.barcodeInput.value.trim());
        }
    }

    async searchAndAddByBarcode(barcode) {
        try {
            const lokId = this.lokSelect ? this.lokSelect.value : (this.isPurchase ? 1 : 2);
            const url = `index.php?ajax_search_barang=1&exact=1&q=${encodeURIComponent(barcode)}&lokasi_id=${lokId}&is_purchase=${this.isPurchase ? 1 : 0}`;
            const res = await fetch(url);
            const data = await res.json();
            
            if (data && data.length > 0) {
                this.addItem(data[0]);
                this.barcodeInput.value = '';
                this.suggestionsBox.innerHTML = '';
                this.suggestionsBox.style.display = 'none';
            } else {
                showToast("Barang tidak ditemukan!", "danger");
            }
        } catch (error) {
            console.error('Error finding barcode:', error);
        }
    }

    addItem(item) {
        // Verify stock first if it is sales/penjualan
        if (!this.isPurchase && item.stok <= 0) {
            showToast(`Stok barang '${item.nama_barang}' habis!`, "warning");
            return;
        }

        const existing = this.items.find(i => i.kode_barang === item.kode_barang);
        if (existing) {
            if (!this.isPurchase && existing.qty >= item.stok) {
                showToast(`Stok '${item.nama_barang}' di etalase tidak mencukupi!`, "warning");
                return;
            }
            existing.qty += 1;
        } else {
            const harga = this.isPurchase ? parseFloat(item.harga_beli) : parseFloat(item.harga_jual);
            this.items.push({
                kode_barang: item.kode_barang,
                nama_barang: item.nama_barang,
                satuan: item.satuan,
                harga: harga,
                qty: 1,
                max_stok: this.isPurchase ? 999999 : parseInt(item.stok)
            });
        }

        this.renderItems();
    }

    removeItem(kode_barang) {
        this.items = this.items.filter(i => i.kode_barang !== kode_barang);
        this.renderItems();
    }

    updateQty(kode_barang, qty) {
        const item = this.items.find(i => i.kode_barang === kode_barang);
        if (item) {
            const parsedQty = parseInt(qty);
            if (isNaN(parsedQty) || parsedQty <= 0) {
                item.qty = 1;
            } else if (!this.isPurchase && parsedQty > item.max_stok) {
                showToast(`Maksimal stok etalase adalah ${item.max_stok}!`, "warning");
                item.qty = item.max_stok;
            } else {
                item.qty = parsedQty;
            }
        }
        this.renderItems();
    }

    updateHarga(kode_barang, harga) {
        const item = this.items.find(i => i.kode_barang === kode_barang);
        if (item) {
            const parsedHarga = parseFloat(harga);
            item.harga = isNaN(parsedHarga) ? 0 : parsedHarga;
        }
        this.renderItems();
    }

    renderItems() {
        this.itemsTableBody.innerHTML = '';
        
        if (this.items.length === 0) {
            this.itemsTableBody.innerHTML = `
                <tr>
                    <td colspan="${this.isPurchase ? 6 : 5}" class="text-center py-4 text-secondary">
                        Belum ada barang dipilih. Silakan cari di atas.
                    </td>
                </tr>
            `;
            this.calculateTotal();
            return;
        }

        this.items.forEach((item, idx) => {
            const tr = document.createElement('tr');
            
            const subtotal = item.qty * item.harga;
            const formatSubtotal = new Intl.NumberFormat('id-ID').format(subtotal);
            const formatHarga = new Intl.NumberFormat('id-ID').format(item.harga);

            tr.innerHTML = `
                <td data-label="Barang">
                    <strong>${item.kode_barang}</strong><br>
                    <span class="text-secondary">${item.nama_barang}</span>
                    <input type="hidden" name="items[${idx}][kode_barang]" value="${item.kode_barang}">
                </td>
                <td data-label="Harga">
                    ${this.isPurchase ? 
                        `Rp <input type="number" name="items[${idx}][harga_beli]" value="${item.harga}" class="form-control form-control-sm d-inline-block" style="width:110px;" onchange="window.currentTx.updateHarga('${item.kode_barang}', this.value)" required>` 
                        : `Rp ${formatHarga}
                           <input type="hidden" name="items[${idx}][harga_jual]" value="${item.harga}">`
                    }
                </td>
                <td data-label="Jumlah">
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <input type="number" name="items[${idx}][jumlah]" value="${item.qty}" min="1" max="${item.max_stok}" inputmode="numeric" class="form-control form-control-sm text-center" style="width:70px;" onchange="window.currentTx.updateQty('${item.kode_barang}', this.value)" required>
                        <span class="text-secondary">${item.satuan}</span>
                    </div>
                </td>
                <td data-label="Subtotal">Rp ${formatSubtotal}</td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm" onclick="window.currentTx.removeItem('${item.kode_barang}')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </td>
            `;
            this.itemsTableBody.appendChild(tr);
        });

        this.calculateTotal();
    }

    calculateTotal() {
        const total = this.items.reduce((sum, item) => sum + (item.qty * item.harga), 0);
        const formatTotal = new Intl.NumberFormat('id-ID').format(total);
        
        if (this.grandTotalEl) this.grandTotalEl.innerText = 'Rp ' + formatTotal;
        if (this.grandTotalInput) this.grandTotalInput.value = total;
    }

    handleSubmit(e) {
        if (this.items.length === 0) {
            e.preventDefault();
            showToast("Silakan tambahkan minimal 1 barang!", "warning");
            return;
        }

        if (this.payTypeSelect && this.payTypeSelect.value === 'kredit') {
            const dpInput = this.form.querySelector('input[name="uang_muka"]');
            const dp = dpInput ? parseFloat(dpInput.value) : 0;
            const total = this.items.reduce((sum, item) => sum + (item.qty * item.harga), 0);
            
            if (isNaN(dp) || dp < 0) {
                e.preventDefault();
                showToast("Jumlah Uang Muka / DP tidak valid!", "warning");
                return;
            }
            if (dp > total) {
                e.preventDefault();
                showToast("Uang Muka / DP tidak boleh melebihi Total Transaksi!", "warning");
                return;
            }
        }
    }
}
