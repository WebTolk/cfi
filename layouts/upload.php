<?php
/*
 * @package    System - CFI
 * @version   2.0.0
 * @author     Sergey Tolkachyov
 * @copyright Copyright (c) 2023 Fictionlabs. All rights reserved.
 * @license   GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link       https://web-tolk.ru
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\Filesystem\Helper as FilesystemHelper;
use Joomla\Filesystem\Path;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

extract($displayData);

/**
 * Layout variables
 * -----------------
 * @var   string $id   DOM id of the field.
 * @var   string $name Name of the input field.
 */

$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->registerAndUseStyle('cfi.upload.style', 'plg_system_cfi/upload.css')
	->registerAndUseScript('cfi.upload.script', 'plg_system_cfi/upload.js',[], ['defer' => true], ['core']);

Text::script('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_NO_FILE');
Text::script('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_FILE_TOO_BIG');
Text::script('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_WRONG_MIME_TYPE');
Text::script('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_UNKNOWN');
Text::script('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_EMPTY');
Text::script('PLG_CFI_IMPORT_UPLOAD_FILE_OK');
Text::script('PLG_CFI_IMPORT_UPLOAD_DRAG_FILE_HERE');
Text::script('PLG_CFI_IMPORT_UPLOAD_WARN_SERVER_DENY_FILE_UPLOAD');
Text::script('PLG_CFI_IMPORT_UPLOAD_WARN_NO_FILE_SELECTED');
Text::script('PLG_CFI_IMPORT_UPLOAD_WARN_UPLOADERROR');

$maxSizeBytes =  FilesystemHelper::getFileUploadMaxSize();
$maxSize      = HTMLHelper::_('number.bytes', $maxSizeBytes);

?>

<div class="upload-field">
    <div class="upload-field__upload upload" data-upload="container" data-state="pending">
        <div class="text-center">
            <p>
                <span class="upload__icon icon-upload" aria-hidden="true"></span>
            </p>
            <div id="upload-progress" class="upload__progress" data-upload="progress">
                <div class="progress">
                    <div class="progress-bar progress-bar-striped bg-success progress-bar-animated"
                         style="width: 0;"
                         role="progressbar"
                         aria-valuenow="0"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         data-upload="bar"
                    ></div>
                </div>
                <p class="lead">
                    <span class="uploading-text">
                        <?php echo Text::_('PLG_CFI_IMPORT_UPLOAD_UPLOADING'); ?>
                    </span>
                    <span class="uploading-number" data-upload="percent">0</span><span class="uploading-symbol">%</span>
                </p>
            </div>
            <div class="upload__actions">
                <p class="lead">
					<?php echo Text::_('PLG_CFI_IMPORT_UPLOAD_DRAG_FILE_HERE'); ?>
                </p>
                <p class="lead" data-upload="message"></p>
                <p>
                    <button id="select-file-button" type="button" class="btn btn-success" data-upload="button">
                        <span class="icon-copy" aria-hidden="true"></span>
						<?php echo Text::_('PLG_CFI_IMPORT_UPLOAD_SELECT_FILE'); ?>
                    </button>
                </p>
                <p>
					<?php echo Text::sprintf('JGLOBAL_MAXIMUM_UPLOAD_SIZE_LIMIT', '&#x200E;' . $maxSize); ?>
                </p>
            </div>
        </div>

        <input name="upload_file" type="file"data-upload="file" value="" accept=".csv"> <!--  accept="image/*" -->
        <input name="max_upload_size" type="hidden" data-upload="size" value="<?php echo $maxSizeBytes; ?>"/>

        <div id="loading" class="d-none align-items-center justify-content-center"></div>
    </div>
</div>
