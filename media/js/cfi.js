/**
 * @package    System - CFI
 * @subpackage  System.cfi
 * @copyright   Copyright (C) Aleksey A. Morozov. All rights reserved.
 * @license     GNU General Public License version 3 or later; see http://www.gnu.org/licenses/gpl-3.0.txt
 */

((CFI) => {
    'use strict';

    CFI.taskId = '';

    CFI.activeTaskType = 'export';

    CFI.hasError = false;


    CFI.startTask = () => {
        const startTaskUrl = Joomla.getOptions('system.paths').baseFull + 'index.php?option=com_ajax&plugin=cfi&group=system&format=json&action=start_task&' + Joomla.getOptions('csrf.token') + '=1&task_id='+CFI.taskId + '&task_type='+CFI.activeTaskType;
        console.log('startTask....');
        Joomla.request({
            // Find the action url associated with the form - we need to add the token to this
            url: startTaskUrl,
            method: 'GET'
        });
    }

    /**
     * Main export function
     */
    CFI.export = () => {
        console.log('export......');
        CFI.taskId = Date.now();
        CFI.activeTaskType = 'export'
        CFI.clearProgressBar();
        const downloadBtn = document.getElementById('cfi-export-download-btn');
        downloadBtn.classList.add('d-none');
        CFI.startTask();
        const convertSwitch = document.getElementById('progress-switch-convert-cp');

        let exportUrl = Joomla.getOptions('system.paths').baseFull + 'index.php?option=com_ajax&plugin=cfi&group=system&format=json&action=export_articles&' + Joomla.getOptions('csrf.token') + '=1&task_id='+CFI.taskId;
        if(convertSwitch && convertSwitch.checked === true) {
            exportUrl += '&cficonvert=1';
        }
        Joomla.request({
            // Find the action url associated with the form - we need to add the token to this
            url: exportUrl,
            method: 'GET',
            onBefore: (xhr) => {
                CFI.checkTaskStatus(CFI.taskId);
            },
            onSuccess: response => {
                console.log('export request ....');
                response = JSON.parse(response);
                if (response.success === false) {

                    CFI.hasError = true;
                    console.error('CFI: '+ response.message);
                    Joomla.renderMessages({
                        error: [response.message]
                    });
                    CFI.clearProgressBar();
                    CFI.deleteTaskIdFile(CFI.taskId);
                }
            },
            onError: function (xhr, status, error) {
                CFI.hasError = true;
                Joomla.renderMessages({
                    error: [Joomla.Text._('PLG_CFI_EXPORT_ERROR')]
                });
            }
        });

    }

    /**
     * @param {string} taskId
     */
    CFI.checkTaskStatus = (taskId) => {
console.log('checkTaskStatus......');
        const checkTaskStatusUrl = Joomla.getOptions('system.paths').baseFull + 'index.php?option=com_ajax&plugin=cfi&group=system&format=json&action=check_status&' + Joomla.getOptions('csrf.token') + '=1&task_id='+taskId;
        const checkInterval = setInterval(async () => {
            console.log('checkTaskStatus - checkInterval......');
            if(CFI.hasError === true) {
                clearInterval(checkInterval);
                CFI.clearProgressBar();
                CFI.deleteTaskIdFile(taskId);
            }

            try {
                // let data;
                Joomla.request({
                    // Find the action url associated with the form - we need to add the token to this
                    url: checkTaskStatusUrl,
                    method: 'GET',
                    onSuccess: response => {

                        response = JSON.parse(response);
                        let status = response.data[0];
                        if(status.message) {
                            console.error('CFI: '+status.message);
                            clearInterval(checkInterval);
                            CFI.clearProgressBar();
                            CFI.deleteTaskIdFile(taskId);
                            return;
                        }

                        CFI.updateProgressBar(status.current, status.total);
                        if(CFI.activeTaskType === 'import') {
                            CFI.updateImportProgressData(status);
                        }
                        if (status.status === 'completed') {
                            CFI.deleteTaskIdFile(taskId);
                            CFI.finishTask(status, CFI.activeTaskType);
                            clearInterval(checkInterval);
                        }
                    },
                    onError: function (xhr, status, error) {
                        Joomla.renderMessages({
                            error: [Joomla.Text._('Ошибка получения статуса задачи!')]
                        });
                    }
                });

            } catch (error) {
                console.error('Ошибка при опросе статуса:', error);
                clearInterval(checkInterval);
            }
        }, 1000); // Опрос каждую секунду

    }

    CFI.finishTask = (status, type) => {
        if(type === 'export') {
            const downloadBtn = document.getElementById('cfi-export-download-btn');
            if(downloadBtn.classList.contains('d-none')) {
                downloadBtn.classList.remove('d-none');
            }
            downloadBtn.setAttribute('href', status.url);
            Joomla.renderMessages({
                success: [Joomla.Text._('PLG_CFI_EXPORT_SUCCESS')]
            });
        } else {
            Joomla.renderMessages({
                success: [Joomla.Text._('PLG_CFI_IMPORT_SUCCESS')]
            });
        }


    }

    CFI.deleteTaskIdFile = (taskId) => {
        const deleteTaskStatusFile = Joomla.getOptions('system.paths').baseFull + 'index.php?option=com_ajax&plugin=cfi&group=system&format=json&action=delete_task_file&' + Joomla.getOptions('csrf.token') + '=1&task_id='+taskId;
        Joomla.request({
            url: deleteTaskStatusFile,
        });
    }

    CFI.clearProgressBar = () => {
        const progressBar = document.getElementById('cfi-progress-bar');
        const progressBarLabel = progressBar.querySelector('#progress-label');
        const progressBarIcon = progressBar.querySelector('#progress-icon');

        progressBar.setAttribute('aria-valuenow', '0');
        progressBarLabel.style.width = '';
        progressBarLabel.innerHTML = '';
        progressBarIcon.classList.add('d-none');

    }

    CFI.updateProgressBar = (current, total) => {

        const progressBar = document.getElementById('cfi-progress-bar');
        const progressBarLabel = progressBar.querySelector('#progress-label');
        const progressBarIcon = progressBar.querySelector('#progress-icon');

        current = parseInt(current);
        total = parseInt(total);

        let percent = current * 100 / total;
        progressBar.setAttribute('aria-valuenow', current);
        progressBarLabel.style.width = percent + "%";
        progressBarLabel.innerHTML = current + ' / ' + total;
        if(progressBarIcon.classList.contains('d-none')) {
            progressBarIcon.classList.remove('d-none');
        }

    }

    /**
     * Receive info about uploaded file from
     * CFIUpload script. Upload has been successful.
     * We can show an import button
     *
     * @param uploadData
     */
    CFI.addUploadData = (uploadData) => {
        console.log('uploadData from CFI');
        console.log(uploadData);
        if(uploadData){

            const uploadFileInfo = document.getElementById('cfi-import-upload-file-info');
            if(uploadFileInfo.classList.contains('d-none')) {
                uploadFileInfo.classList.remove('d-none')
            }
            const uploadFileInfoFilename = document.getElementById('cfi-import-upload-file-info-filename');
            uploadFileInfoFilename.innerHTML = uploadData.filename;
            const uploadFileInfoFines = document.getElementById('cfi-import-upload-file-info-lines');
            uploadFileInfoFines.innerHTML = uploadData.articles_count;
            CFI.taskId = uploadData.taskId;
            CFI.showImportBtn();
        }
    }

    CFI.showImportBtn = () => {
        const importBtn = document.getElementById('cfi-import-btn');
        if(importBtn.classList.contains('d-none')) {
            importBtn.classList.remove('d-none');
        }
    }

    /**
     * Main import function
     */
    CFI.import = () => {
        console.log('import......');
        CfiUpload.resetArea();
        CFI.activeTaskType = 'import';
        CFI.clearProgressBar();
        CFI.startTask();
        const convertSwitch = document.getElementById('progress-switch-convert-cp');

        let importUrl = Joomla.getOptions('system.paths').baseFull + 'index.php?option=com_ajax&plugin=cfi&group=system&format=json&action=import_articles&' + Joomla.getOptions('csrf.token') + '=1&task_id='+CFI.taskId;

        if(convertSwitch && convertSwitch.checked === true) {
            importUrl += '&cficonvert=1';
        }

        Joomla.request({
            // Find the action url associated w ith the form - we need to add the token to this
            url: importUrl,
            method: 'GET',
            onBefore: (xhr) => {
                CFI.checkTaskStatus(CFI.taskId);
            },
            onSuccess: response => {
                console.log('import request ....');
                try {
                    response = JSON.parse(response);
                    if (response && response.messages) {
                        Joomla.renderMessages(response.messages);
                    }
                    if (response && response.success === false) {
                        CFI.hasError = true;
                        console.error('CFI: '+ response.message);
                        Joomla.renderMessages({
                            error: [response.message]
                        });
                        CFI.clearProgressBar();
                        CFI.deleteTaskIdFile(CFI.taskId);
                    }

                } catch (error) {
                    console.error(error);
                    CFI.hasError = true;
                    CFI.clearProgressBar();
                    CFI.deleteTaskIdFile(CFI.taskId);
                    return false;
                }
            },
            onError: function (xhr, status, error) {
                CFI.hasError = true;
                Joomla.renderMessages({
                    error: [Joomla.Text._('PLG_CFI_IMPORT_ERROR')]
                });
            }
        });

    }

    /**
     *
     * @param {object} data
     */
    CFI.updateImportProgressData = (data) => {

        const importCurrentArticleTitle = document.getElementById('import-current-article-title');
        if(data.current_article_title !== '') {
            if(importCurrentArticleTitle.parentNode.classList.contains('d-none')) {
                importCurrentArticleTitle.parentNode.classList.remove('d-none');
                importCurrentArticleTitle.innerHTML = data.current_article_title;
            }
        } else {
            importCurrentArticleTitle.parentNode.classList.add('d-none');
        }

        const dataWrapper = document.getElementById('cfi-import-progress-data-wrapper');
        if(dataWrapper.classList.contains('d-none')) {
            dataWrapper.classList.remove('d-none')
        }
        const continues = document.getElementById('cfi-import-progress-data-continues');
        continues.innerHTML = data.continues;
        const errors = document.getElementById('cfi-import-progress-data-errors');
        errors.innerHTML = data.errors_count;
        const inserts = document.getElementById('cfi-import-progress-data-inserts');
        inserts.innerHTML = data.inserts;
        const updates = document.getElementById('cfi-import-progress-data-updates');
        updates.innerHTML = data.updates;
        const errorList = document.getElementById('cfi-import-progress-data-error-list');
        const errorListWrapper = errorList.parentNode;
        if(data.errors) {
            if(errorListWrapper.classList.contains('d-none')) {
                errorListWrapper.classList.remove('d-none')
            }
            let list = document.createElement('ul');
            Object.entries(data.errors).forEach((value, index, array) => {
                let listElem = document.createElement('li');
                listElem.innerHTML = value;
                list.appendChild(listElem);
            });
            errorList.innerHTML = ''; // очищаем контейнер
            errorList.appendChild(list); // добавляем список в контейнер
        }


    };


    /**
     * Начало...
     */
    CFI.init = () => {
console.log('init...');
        CFI.hasError = false;
        const exportBtn = document.getElementById('cfi-export-btn');
        if(exportBtn) {
            exportBtn.addEventListener('click', CFI.export);
        }

        const downloadBtn = document.getElementById('cfi-export-download-btn');
        if(downloadBtn){
            downloadBtn.addEventListener('click', CFI.clearProgressBar);
        }

        const importBtn = document.getElementById('cfi-import-btn');
        if(importBtn) {
            importBtn.addEventListener('click', CFI.import);
        }

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', CFI.init);
    } else {
        CFI.init();
    }

})(window.CFI = window.CFI || {});

document.addEventListener('DOMContentLoaded', function () {

    var cfiBtnToolbar = document.getElementById('js-cfi-toolbarbtn');
    var cfiBtnClose = document.getElementById('js-cfi-wellclose');
    var cfiWell = document.getElementById('js-cfi-well');
    var cfiDropArea = document.getElementById('js-cfi-dropzone');
    var cfiDropInput = document.getElementById('js-cfi-file');
    var cfiDropLabel = document.getElementById('js-cfi-importlabel');
    var cfiCbConvert = document.getElementById('js-cfi-convert');
    var cfiExportArea = document.getElementById('js-cfi-expzone');
    var cfiSelCategories = document.getElementById('js-cfi-categories');
    var cfiBtnExport = document.getElementById('js-cfi-export');
    var cfiLabelExport = document.getElementById('js-cfi-exportlabel');

    if (cfiBtnToolbar && cfiWell) {
        cfiBtnToolbar.addEventListener('click', cfi_toggleWell, false);
        cfiBtnClose.addEventListener('click', cfi_toggleWell, false);

        cfiDropArea.addEventListener('dragenter', cfi_preventDefaults, false);
        cfiDropArea.addEventListener('dragover', cfi_preventDefaults, false);
        cfiDropArea.addEventListener('dragleave', cfi_preventDefaults, false);
        cfiDropArea.addEventListener('drop', cfi_preventDefaults, false);
        cfiDropArea.addEventListener('dragenter', cfi_highlight, false);
        cfiDropArea.addEventListener('dragover', cfi_highlight, false);
        cfiDropArea.addEventListener('dragleave', cfi_unhighlight, false);
        cfiDropArea.addEventListener('drop', cfi_unhighlight, false);
        cfiDropArea.addEventListener('drop', cfi_handleDrop, false);

        cfiDropInput.addEventListener('change', cfi_handleFiles, false);

        cfiBtnExport.addEventListener('click', cfi_export, false);
    }

    function cfi_toggleWell() {
        if (cfiWell.classList.contains('hidden')) {
            cfi_clearState();
        }
        cfiWell.classList.toggle('hidden');
    }

    function cfi_clearState() {
        cfiDropArea.classList.remove('alert-success', 'alert-error', 'cfi-dropzone-highlight');
        cfiDropLabel.innerHTML = cfiDropArea.dataset.ready;
    }

    function cfi_preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    function cfi_highlight() {
        cfiDropArea.classList.remove('alert-success', 'alert-error');
        cfiDropArea.classList.add('cfi-dropzone-highlight');
    }

    function cfi_unhighlight() {
        cfiDropArea.classList.remove('cfi-dropzone-highlight');
    }

    function cfi_handleDrop(e) {
        var dt = e.dataTransfer;
        var files = dt.files;
        cfi_handleDropFiles(files);
    }

    function cfi_handleFiles() {
        cfi_handleDropFiles(this.files);
    }

    function cfi_handleDropFiles(files) {
        files = [...files];
        files.forEach(cfi_uploadFile);
    }

    function cfi_uploadFile(file, i) {
        cfiLabelExport.innerHTML = '';
        cfiDropArea.classList.remove('alert-success', 'alert-error', 'cfi-dropzone-highlight');
        cfiDropLabel.innerHTML = cfiDropArea.dataset.worktitle;
        cfiDropArea.style.pointerEvents = 'none';
        cfiExportArea.style.pointerEvents = 'none';
        var url = location.protocol + '//' + location.host + Joomla.getOptions('system.paths')['base'] +
            '/index.php?option=com_ajax&group=system&plugin=cfi&method=post&format=raw';
        var xhr = new XMLHttpRequest();
        var formData = new FormData();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-Token', Joomla.getOptions('csrf.token'));

        xhr.addEventListener('readystatechange', function (e) {
            if (xhr.readyState == 4) {
                if (xhr.status == 200) {
                    var response = false;
                    try {
                        response = JSON.parse(xhr.response);
                        cfiDropArea.classList.remove('cfi-dropzone-highlight');
                        cfiDropArea.classList.add('alert-' + (response.result ? 'success' : 'error'));
                        cfiDropLabel.innerHTML = '<strong>' + (response.result
                            ? cfiDropArea.dataset.success
                            : cfiDropArea.dataset.error) + '</strong><br>' + response.message;
                    } catch (e) {
                        cfiDropArea.classList.remove('cfi-dropzone-highlight');
                        cfiDropArea.classList.add('alert-error');
                        cfiDropLabel.innerHTML = '<strong>' + cfiDropArea.dataset.error + '</strong><span>' +
                            xhr.response + '</span>';
                    }
                    cfiDropArea.style.pointerEvents = 'auto';
                    cfiExportArea.style.pointerEvents = 'auto';
                    if (response && response.result) {
                        var t = 10;
                        setInterval(function () {
                            t--;
                            document.getElementById('cfi-result-counter').innerText = t;
                            if (!t) {
                                location.reload();
                            }
                        }, 1000);
                    }
                } else {
                    cfiDropArea.classList.remove('cfi-dropzone-highlight');
                    cfiDropArea.classList.add('alert-error');
                    cfiDropLabel.innerHTML = '<strong>' + xhr.status +
                        '</strong><span>Unknown error, look at the log</span>';
                    cfiDropArea.style.pointerEvents = 'auto';
                    cfiExportArea.style.pointerEvents = 'auto';
                }
            }
        });

        formData.append('cfifile', file);
        formData.append('cfistate', 'import');
        formData.append('cficonvert', Number(cfiCbConvert.checked));
        formData.append(Joomla.getOptions('csrf.token'), '1');
        xhr.send(formData);
    }

    function cfi_export() {
        if (cfiSelCategories.value < 1) {
            cfiLabelExport.classList.add('text-error');
            cfiLabelExport.innerHTML = cfiBtnExport.dataset.error + '<br>' + cfiBtnExport.dataset.nosel;
            return;
        }

        cfiLabelExport.innerHTML = '';
        cfiLabelExport.classList.remove('text-success', 'text-error');
        cfiDropArea.style.pointerEvents = 'none';
        cfiExportArea.style.pointerEvents = 'none';
        var url = location.protocol + '//' + location.host + Joomla.getOptions('system.paths')['base'] +
            '/index.php?option=com_ajax&group=system&plugin=cfi';
        var xhr = new XMLHttpRequest();
        var formData = new FormData();
        xhr.open('POST', url + '&format=json', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-Token', Joomla.getOptions('csrf.token'));

        xhr.addEventListener('readystatechange', function (e) {
            if (xhr.readyState == 4) {
                if (xhr.status == 200) {
                    try {
                        var response = JSON.parse(xhr.response);
                        if (response.result) {
                            cfiLabelExport.classList.add('text-success');
                            cfiLabelExport.innerHTML = cfiBtnExport.dataset.success;
                            window.location = url + '&format=raw&cfistate=download&f=' + response.f + '&' +
                                Joomla.getOptions('csrf.token') + '=1';
                        } else {
                            cfiLabelExport.classList.add('text-error');
                            cfiLabelExport.innerHTML = cfiBtnExport.dataset.error + '<br>' + response.message;
                        }
                    } catch (e) {
                        cfiLabelExport.classList.add('text-error');
                        cfiLabelExport.innerHTML = cfiBtnExport.dataset.error + '<br>' + xhr.response;
                    }
                    cfiDropArea.style.pointerEvents = 'auto';
                    cfiExportArea.style.pointerEvents = 'auto';
                } else {
                    cfiDropArea.classList.remove('cfi-dropzone-highlight');
                    cfiDropArea.classList.add('alert-error');
                    cfiLabelExport.innerHTML = '<strong>' + xhr.status +
                        '</strong><span>Unknown error, look at the log</span>';
                    cfiDropArea.style.pointerEvents = 'auto';
                    cfiExportArea.style.pointerEvents = 'auto';
                }
            }
        });

        formData.append('cficat', cfiSelCategories.value);
        formData.append('cfistate', 'export');
        formData.append('cficonvert', cfiCbConvert.checked);
        formData.append(Joomla.getOptions('csrf.token'), '1');
        xhr.send(formData);
    }

});
