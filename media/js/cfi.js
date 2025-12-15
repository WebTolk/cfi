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

	CFI.stopped = false;

	CFI.taskInterval = null;

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
		console.log(CFI);
        console.log('export......');
        CFI.taskId = Date.now();
        CFI.activeTaskType = 'export';
		CFI.enableStopBtn()
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
					CFI.stopped = true;
					if (CFI.taskInterval) {
						clearInterval(CFI.taskInterval);
						CFI.taskInterval = null;
					}
					CFI.disableStopBtn();
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
				CFI.stopped = true;
				if (CFI.taskInterval) {
					clearInterval(CFI.taskInterval);
					CFI.taskInterval = null;
				}
				CFI.disableStopBtn();
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
		if(CFI.stopped === true) {
			return;
		}
console.log('checkTaskStatus......');
        const checkTaskStatusUrl = Joomla.getOptions('system.paths').baseFull + 'index.php?option=com_ajax&plugin=cfi&group=system&format=json&action=check_status&' + Joomla.getOptions('csrf.token') + '=1&task_id='+taskId;

		CFI.taskInterval = setInterval(async () => {
            console.log('checkTaskStatus - checkInterval......');
            if(CFI.hasError === true || CFI.stopped === true) {
				clearInterval(CFI.taskInterval);
				CFI.taskInterval = null;
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
                            clearInterval(CFI.taskInterval);
							CFI.taskInterval = null;
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
							clearInterval(CFI.taskInterval);
							CFI.taskInterval = null;
                        }
                    },
                    onError: function (xhr, status, error) {
						clearInterval(CFI.taskInterval);
						CFI.taskInterval = null;
                        Joomla.renderMessages({
                            error: [Joomla.Text._('Ошибка получения статуса задачи!')]
                        });
                    }
                });

            } catch (error) {
                console.error('Ошибка при опросе статуса:', error);
				clearInterval(CFI.taskInterval);
				CFI.taskInterval = null;
            }
        }, 1000);

    }

    CFI.finishTask = (status, type) => {
        if(type === 'export') {
            const downloadBtn = document.getElementById('cfi-export-download-btn');
            if(downloadBtn.classList.contains('d-none')) {
                downloadBtn.classList.remove('d-none');
            }
            downloadBtn.setAttribute('href', status.url);

            let date = new Date(CFI.taskId)
                .toISOString()
                .replace(/T/, '_')
                .replace(/-/g, '_')
                .replace(/:/g, '_')
                .slice(0, 16);
            let filename = 'cfi_export_' + date;
            downloadBtn.setAttribute('download', filename);
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

    /**
     * @param {string} current Current position
     * @param {string} total Total articles
     */
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
	 * disable stop button
	 */
	CFI.disableStopBtn = () => {
        const stopBtn = document.getElementById('cfi-stop-task-btn');
        stopBtn.disabled = true;
    }
	/**
	 * Enable stop button
	 */
	CFI.enableStopBtn = () => {
        const stopBtn = document.getElementById('cfi-stop-task-btn');
        if(stopBtn.disabled === true) {
			stopBtn.disabled = false;
        }
		stopBtn.addEventListener('click', CFI.stopTask);
    }

	/**
	 * make ajax request to stop task
	 */
	CFI.stopTask = () => {
		let stopUrl = Joomla.getOptions('system.paths').baseFull + 'index.php?option=com_ajax&plugin=cfi&group=system&format=json&action=stop_task&' + Joomla.getOptions('csrf.token') + '=1&task_id='+CFI.taskId;
		console.log('stopTask....');
		Joomla.request({
			url: stopUrl,
			method: 'GET',
			onSuccess: response => {
				console.log('stop request ....');
				if(response === '') {
					console.warn('CFI: CFI.Import() got an empty response for ajax request.')
				}
			}
		});

		CFI.stopped = true;
		if (CFI.taskInterval) {
			clearInterval(CFI.taskInterval);
			CFI.taskInterval = null;
		}
		Joomla.renderMessages({
			info: [Joomla.Text._('PLG_CFI_PROCESS_CANCELED_BY_USER')]
		});
		CFI.init();
    }

    /**
     * Main import function
     */
    CFI.import = () => {
		console.log(CFI);
		if(CFI.stopped === true) {
			return;
		}
        console.log('import......');
        CfiUpload.resetArea();
		CFI.enableStopBtn();
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
                if(response === '') {
                    console.warn('CFI: CFI.Import() got an empty response for ajax request.')
                    return;
                }

                try {

                    response = JSON.parse(response);
                    if (response && response.messages) {
                        Joomla.renderMessages(response.messages);
                    }
                    if (response && response.success === false) {
                        CFI.hasError = true;
						CFI.stopped = true;
						if (CFI.taskInterval) {
							clearInterval(CFI.taskInterval);
							CFI.taskInterval = null;
						}
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
					CFI.stopped = true;
					if (CFI.taskInterval) {
						clearInterval(CFI.taskInterval);
						CFI.taskInterval = null;
					}
					CFI.disableStopBtn();
                    CFI.clearProgressBar();
                    CFI.deleteTaskIdFile(CFI.taskId);
                    return false;
                }
            },
            onError: function (xhr, status, error) {
                CFI.hasError = true;
				CFI.stopped = true;
				if (CFI.taskInterval) {
					clearInterval(CFI.taskInterval);
					CFI.taskInterval = null;
				}
				CFI.disableStopBtn();
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

        const importCurrentArticleTitleWrapper = document.getElementById('import-current-article-title-wrapper');
        const importCurrentArticleTitle = document.getElementById('import-current-article-title');
        if(data.current_article_title !== ''){
            if(importCurrentArticleTitleWrapper.classList.contains('d-none')) {
                importCurrentArticleTitleWrapper.classList.remove('d-none');
            }
        } else {
            importCurrentArticleTitleWrapper.classList.add('d-none');
        }
        importCurrentArticleTitle.innerHTML = data.current_article_title;

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
        CFI.stopped = false;
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

		const stopBtn = document.getElementById('cfi-stop-task-btn');
        if(stopBtn) {
			CFI.disableStopBtn();
        }

		CFI.clearProgressBar();

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', CFI.init);
    } else {
        CFI.init();
    }

})(window.CFI = window.CFI || {});