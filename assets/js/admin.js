(function($) {
    'use strict';

    const BPI = {
        stats: { created: 0, updated: 0, skipped: 0, errors: [] },
        
        init: function() {
            this.bindEvents();
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
                        this.updateAction(`Found ${response.data.total_products} products in ${response.data.excel_files} Excel file(s)`);
                        setTimeout(() => this.processBatch(), 500);
                    } else {
                        this.showError(response.data.message);
                    }
                },
                error: () => this.showError('Upload failed. Please try again.')
            });
        },

        processBatch: function() {
            this.updateAction('Processing products...');

            $.ajax({
                url: bpiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bpi_process_batch',
                    nonce: bpiData.nonce,
                    batch_size: $('#bpi-batch-size').val()
                },
                success: (response) => {
                    if (response.success) {
                        const data = response.data;
                        this.stats.created += data.created;
                        this.stats.updated += data.updated;
                        this.stats.skipped += data.skipped;
                        this.stats.errors = this.stats.errors.concat(data.errors);

                        this.updateProgress(data.processed, data.total);

                        if (data.complete) {
                            this.cleanup();
                        } else {
                            setTimeout(() => this.processBatch(), 100);
                        }
                    } else {
                        this.showError(response.data.message);
                    }
                },
                error: () => this.showError('Processing failed. Please try again.')
            });
        },

        cleanup: function() {
            this.updateAction('Cleaning up temporary files...');
            $.post(bpiData.ajaxUrl, { action: 'bpi_cleanup', nonce: bpiData.nonce }, () => this.showResults());
        },

        showProgress: function() {
            $('#bpi-submit-btn').prop('disabled', true).text('Importing...');
            $('#bpi-progress-section').removeClass('hidden');
            $('#bpi-results-section').addClass('hidden');
        },

        updateProgress: function(processed, total) {
            const pct = Math.round((processed / total) * 100);
            $('#bpi-progress-fill').css('width', pct + '%');
            $('#bpi-progress-text').text(pct + '%');
            $('#bpi-progress-count').text(`${processed} / ${total} products`);
        },

        updateAction: function(text) {
            $('#bpi-current-action').text(text);
        },

        showResults: function() {
            $('#bpi-submit-btn').prop('disabled', false).text('Start Import');
            $('#bpi-results-section').removeClass('hidden');
            
            let html = `<div class="bpi-stats">
                <div class="bpi-stat bpi-stat-success"><span>${this.stats.created}</span> Created</div>
                <div class="bpi-stat bpi-stat-info"><span>${this.stats.updated}</span> Updated</div>
                <div class="bpi-stat bpi-stat-warning"><span>${this.stats.skipped}</span> Skipped</div>
            </div>`;
            $('#bpi-results-summary').html(html);

            if (this.stats.errors.length > 0) {
                $('#bpi-results-log').html('<h4>Errors:</h4><ul>' + 
                    this.stats.errors.map(e => `<li>${e}</li>`).join('') + '</ul>');
            } else {
                $('#bpi-results-log').html('<p class="bpi-success">Import completed successfully!</p>');
            }

            this.updateAction('Import complete!');
        },

        showError: function(message) {
            $('#bpi-submit-btn').prop('disabled', false).text('Start Import');
            $('#bpi-current-action').html(`<span class="bpi-error">${message}</span>`);
        },

        showMessage: function(message, type) {
            alert(message);
        }
    };

    $(document).ready(() => BPI.init());
})(jQuery);

