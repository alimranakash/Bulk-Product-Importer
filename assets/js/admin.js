(function($) {
    'use strict';

    const BPI = {
        importId: null,
        pollInterval: null,
        stats: { created: 0, updated: 0, skipped: 0, errors: [] },

        init: function() {
            this.bindEvents();
            this.checkExistingImport();
        },

        bindEvents: function() {
            const dropZone = $('#bpi-drop-zone');
            const fileInput = document.getElementById('bpi-file-input');

            dropZone.on('click', function(e) {
                if (e.target.id !== 'bpi-file-input') {
                    fileInput.click();
                }
            });
            dropZone.on('dragover dragenter', (e) => { e.preventDefault(); dropZone.addClass('dragover'); });
            dropZone.on('dragleave drop', (e) => { e.preventDefault(); dropZone.removeClass('dragover'); });
            dropZone.on('drop', (e) => this.handleFileSelect(e.originalEvent.dataTransfer.files[0]));
            $('#bpi-file-input').on('change', (e) => this.handleFileSelect(e.target.files[0]));
            $('#bpi-upload-form').on('submit', (e) => { e.preventDefault(); this.startImport(); });
            $('#bpi-cancel-btn').on('click', () => this.cancelImport());
        },

        checkExistingImport: function() {
            $.post(bpiData.ajaxUrl, {
                action: 'bpi_get_progress',
                nonce: bpiData.nonce
            }, (response) => {
                if (response.success && response.data.status === 'processing') {
                    this.importId = response.data.import_id;
                    this.showProgress();
                    this.updateAction('Resuming import progress...');
                    this.startPolling();
                }
            });
        },

        handleFileSelect: function(file) {
            if (!file || !file.name.endsWith('.zip')) {
                this.showMessage('Please select a valid ZIP file', 'error');
                return;
            }
            this.selectedFile = file;
            $('#bpi-file-info').html(`<strong>Selected:</strong> ${file.name} (${this.formatSize(file.size)})`).removeClass('hidden');
            $('#bpi-submit-btn').prop('disabled', false);
        },

        formatSize: function(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
            return bytes.toFixed(1) + ' ' + units[i];
        },

        startImport: function() {
            if (!this.selectedFile) return;

            const formData = new FormData();
            formData.append('action', 'bpi_upload_zip');
            formData.append('nonce', bpiData.nonce);
            formData.append('zip_file', this.selectedFile);

            this.stats = { created: 0, updated: 0, skipped: 0, errors: [] };
            this.showProgress();
            this.updateAction('Uploading and extracting ZIP file...');

            $.ajax({
                url: bpiData.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    if (response.success) {
                        this.totalProducts = response.data.total_products;
                        this.updateAction(`Found ${response.data.total_products} products. Scheduling import...`);
                        setTimeout(() => this.scheduleImport(), 500);
                    } else {
                        this.showError(response.data.message);
                    }
                },
                error: () => this.showError('Upload failed. Please try again.')
            });
        },

        scheduleImport: function() {
            $.post(bpiData.ajaxUrl, {
                action: 'bpi_start_import',
                nonce: bpiData.nonce,
                batch_size: $('#bpi-batch-size').val()
            }, (response) => {
                if (response.success) {
                    this.importId = response.data.import_id;
                    this.updateAction('Import scheduled. Processing in background...');
                    this.startPolling();
                } else {
                    this.showError(response.data.message);
                }
            });
        },

        startPolling: function() {
            if (this.pollInterval) clearInterval(this.pollInterval);
            this.pollProgress();
            this.pollInterval = setInterval(() => this.pollProgress(), 2000);
        },

        stopPolling: function() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        pollProgress: function() {
            $.post(bpiData.ajaxUrl, {
                action: 'bpi_get_progress',
                nonce: bpiData.nonce,
                import_id: this.importId
            }, (response) => {
                if (response.success) {
                    const data = response.data;
                    this.stats = {
                        created: data.created,
                        updated: data.updated,
                        skipped: data.skipped,
                        errors: data.errors
                    };

                    this.updateProgress(data.processed, data.total);

                    const pending = data.pending_actions > 0 ? ` (${data.pending_actions} batches pending)` : '';
                    this.updateAction(`Processing: ${data.processed}/${data.total} products${pending}`);

                    if (data.complete || data.status === 'complete' || data.status === 'cancelled') {
                        this.stopPolling();
                        this.cleanup();
                    }
                } else {
                    this.stopPolling();
                    this.showError(response.data.message);
                }
            });
        },

        cancelImport: function() {
            if (!confirm('Are you sure you want to cancel the import?')) return;

            this.stopPolling();
            $.post(bpiData.ajaxUrl, {
                action: 'bpi_cancel_import',
                nonce: bpiData.nonce,
                import_id: this.importId
            }, () => {
                this.updateAction('Import cancelled');
                this.showResults();
            });
        },

        cleanup: function() {
            this.updateAction('Finalizing...');
            $.post(bpiData.ajaxUrl, { action: 'bpi_cleanup', nonce: bpiData.nonce }, () => this.showResults());
        },

        showProgress: function() {
            $('#bpi-submit-btn').prop('disabled', true).text('Importing...');
            $('#bpi-cancel-btn').removeClass('hidden');
            $('#bpi-progress-section').removeClass('hidden');
            $('#bpi-results-section').addClass('hidden');
        },

        updateProgress: function(processed, total) {
            const pct = total > 0 ? Math.round((processed / total) * 100) : 0;
            $('#bpi-progress-fill').css('width', pct + '%');
            $('#bpi-progress-text').text(pct + '%');
            $('#bpi-progress-count').text(`${processed} / ${total} products`);
        },

        updateAction: function(text) {
            $('#bpi-current-action').text(text);
        },

        showResults: function() {
            $('#bpi-submit-btn').prop('disabled', false).text('Start Import');
            $('#bpi-cancel-btn').addClass('hidden');
            $('#bpi-results-section').removeClass('hidden');

            let html = `<div class="bpi-stats">
                <div class="bpi-stat bpi-stat-success"><span>${this.stats.created}</span> Created</div>
                <div class="bpi-stat bpi-stat-info"><span>${this.stats.updated}</span> Updated</div>
                <div class="bpi-stat bpi-stat-warning"><span>${this.stats.skipped}</span> Skipped</div>
            </div>`;
            $('#bpi-results-summary').html(html);

            if (this.stats.errors.length > 0) {
                $('#bpi-results-log').html('<h4>Errors (last 10):</h4><ul>' +
                    this.stats.errors.map(e => `<li>${e}</li>`).join('') + '</ul>');
            } else {
                $('#bpi-results-log').html('<p class="bpi-success">Import completed successfully!</p>');
            }

            this.updateAction('Import complete!');
            this.importId = null;
        },

        showError: function(message) {
            $('#bpi-submit-btn').prop('disabled', false).text('Start Import');
            $('#bpi-cancel-btn').addClass('hidden');
            $('#bpi-current-action').html(`<span class="bpi-error">${message}</span>`);
        },

        showMessage: function(message, type) {
            alert(message);
        }
    };

    $(document).ready(() => BPI.init());
})(jQuery);

