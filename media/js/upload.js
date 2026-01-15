window.CfiUpload = window.CfiUpload || {};

(CfiUpload => {

	CfiUpload.init = () => {

		CfiUpload.dragZone = document.querySelector('[data-upload="container"]');
		CfiUpload.fileInput = document.querySelector('[data-upload="file"]');
		CfiUpload.button = document.querySelector('[data-upload="button"]');
		CfiUpload.progress = document.querySelector('[data-upload="progress"]');
		CfiUpload.progressBar = document.querySelector('[data-upload="bar"]');
		CfiUpload.percentage = document.querySelector('[data-upload="percent"]');
		CfiUpload.fileSizeMax = document.querySelector('[data-upload="size"]').value;
		CfiUpload.message = document.querySelector('[data-upload="message"]');
		CfiUpload.uploading = false;

		CfiUpload.button.addEventListener('click', function () {

			CfiUpload.fileInput.click();
		});

		CfiUpload.fileInput.addEventListener('change', function (event) {
			if (CfiUpload.uploading) {
				return;
			}
			let canUpload = CfiUpload.checkFile(event);
			if (canUpload === true) {

				CfiUpload.upload(event).then(
					result => {
						CfiUpload.uploadSuccess(result);
					},
					error => {
						CfiUpload.uploadError(error);
					}
				);
			}
		});

		CfiUpload.dragZone.addEventListener('dragenter', function (event) {
			event.preventDefault();
			event.stopPropagation();
			CfiUpload.dragZone.classList.add('hover');
			return false;
		});
		CfiUpload.dragZone.addEventListener('dragover', function (event) {
			event.preventDefault();
			event.stopPropagation();
			CfiUpload.dragZone.classList.add('hover');
			return false;
		});
		CfiUpload.dragZone.addEventListener('dragleave', function (event) {
			event.preventDefault();
			event.stopPropagation();
			CfiUpload.dragZone.classList.remove('hover');
			return false;
		});
		CfiUpload.dragZone.addEventListener('drop', function (event) {
			let canUpload = CfiUpload.checkFile(event);
			if (canUpload === true) {

				CfiUpload.upload(event).then(
					result => {
						CfiUpload.uploadSuccess(result);
					},
					error => {
						CfiUpload.uploadError(error);
					}
				);
			}
		});
	}

	CfiUpload.checkFile = (event) => {

		event.stopPropagation();
		event.preventDefault();
		CfiUpload.resetArea();

		const fileList = event.target.files || event.dataTransfer.files;
		let fileSize = fileList[0].size;
		let fileType = fileList[0].type;

		if (fileSize >= CfiUpload.fileSizeMax) {
			CfiUpload.dragZone.classList.add('shadow-danger');
			CfiUpload.message.innerHTML = Joomla.Text._('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_FILE_TOO_BIG');
			CfiUpload.message.classList.add('text-danger', 'fw-bold');
			return false;
		}

		if (fileType !== 'text/csv') {
			CfiUpload.dragZone.classList.add('shadow-danger');
			CfiUpload.message.innerHTML = Joomla.Text._('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_WRONG_MIME_TYPE');
			CfiUpload.message.classList.add('text-danger', 'fw-bold');

			return false;
		}

		if (CfiUpload.dragZone.classList.contains('shadow-danger')) {
			CfiUpload.dragZone.classList.remove('shadow-danger');
		}
		CfiUpload.dragZone.classList.add('shadow-success');
		CfiUpload.message.innerHTML = Joomla.Text._('PLG_CFI_IMPORT_UPLOAD_FILE_OK');
		CfiUpload.message.classList.add('text-success', 'fw-bold');
		if (CfiUpload.message.classList.contains('text-danger')) {
			CfiUpload.message.classList.remove('text-danger');
		}
		CfiUpload.file = fileList[0];
		return true;
	}

	/**
	 * Reset upload area to default view
	 */
	CfiUpload.resetArea = () => {
		if (CfiUpload.dragZone.classList.contains('shadow-danger')) {
			CfiUpload.dragZone.classList.remove('shadow-danger');
		}
		if (CfiUpload.dragZone.classList.contains('shadow-success')) {
			CfiUpload.dragZone.classList.remove('shadow-success');
		}
		CfiUpload.message.innerHTML = '';
		if (CfiUpload.message.classList.contains('text-danger')) {
			CfiUpload.message.classList.remove('text-danger');
		}
		if (CfiUpload.message.classList.contains('text-success')) {
			CfiUpload.message.classList.remove('text-success');
		}
		CfiUpload.message.innerHTML = Joomla.Text._('PLG_CFI_IMPORT_UPLOAD_DRAG_FILE_HERE');
	}

	CfiUpload.upload = () => {

		let formData = new FormData();
		formData.append('upload_file', CfiUpload.file);

		// Wrap the ajax call into a real promise
		return new Promise((success, error) => {
			const url = new URL(Joomla.getOptions('system.paths').baseFull + "index.php?option=com_ajax&group=system&plugin=cfi&method=post&action=uploadCSV&format=raw");
			url.searchParams.append(Joomla.getOptions('csrf.token'), '1');

			Joomla.request({
				url: url.toString(),
				method: 'POST',
				data: formData,
				perform: true,
				onSuccess: response => {
					success(response);
				},
				onError: xhr => {
					error(xhr);
				}
			});


		}).catch((e) => {
			console.error(e);
		});
	}

	CfiUpload.uploadSuccess = (response) => {

		response = JSON.parse(response);
		console.log('CfiUpload.uploadSuccess');
		console.log(response);
		if (response.success === true) {

			CfiUpload.message.innerHTML = response.message;

			let uploadData = response.data; // true
			if (uploadData !== '') {
				// Send data to main import script
				CFI.addUploadData(uploadData);
				return true;
			}
		} else {
			CfiUpload.resetArea();
			Joomla.renderMessages({
				error: [response.message]
			});
			return false;
		}

	}

	CfiUpload.uploadError = (response) => {
		console.log(response);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', CfiUpload.init);
	} else {
		CfiUpload.init();
	}

})(CfiUpload);
