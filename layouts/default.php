<?php

/**
 * @package    System - CFI
 * @subpackage  System.webauthn
 *
 * @copyright   (C) 2020 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\Helpers\Select;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Layout\LayoutHelper;


/**
 * Passwordless Login management interface
 *
 * Generic data
 *
 * @var   FileLayout $this        The Joomla layout renderer
 * @var   array      $displayData The data in array format. DO NOT USE.
 *
 */
extract($displayData);

/**
 * Layout specific data
 *
 * @var array $export_data
 */

$categories_count = $export_data['categories_count'];
$total_items = $export_data['total_items'];
$filter = $export_data['filter'];
$params = $export_data['params'];
$jcfields = $export_data['jcfields'];
$article_fields = $export_data['article_fields'];

$use_tags = boolval($params['params.use_tags']);
$use_custom_fields = boolval($params['params.use_custom_fields']);

Factory::getApplication()
    ->getDocument()
    ->getWebAssetManager()
    ->useStyle('bootstrap.css')
    ->registerAndUseScript('plg_system_cfi.js','plg_system_cfi/cfi.js', [], ['defer' => true], ['core']);

Text::script('PLG_CFI_EXPORT_SUCCESS');
Text::script('PLG_CFI_IMPORT_SUCCESS');
Text::script('PLG_CFI_EXPORT_ERROR');
Text::script('PLG_CFI_IMPORT_ERROR');
Text::script('PLG_CFI_PROCESS_CANCELED_BY_USER');

?>

<div class="mb-2 p-2 shadow-sm">
	<div class="d-flex justify-content-between align-items-center">
		<div class="w-100 me-2">
			<div id="cfi-progress-bar" class="progress mb-3 position-realtive d-flex align-items-center"
				 role="progressbar" aria-label="<?php echo Text::_('PLG_CFI_EXPORT'); ?>" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
				<img src="/media/system/images/ajax-loader.gif" id="progress-icon" class="d-none position-absolute ms-2"
					 width="10" height="10">
				<div id="progress-label" class="progress-bar" style=""></div>
			</div>

			<div id="import-current-article-title-wrapper" class="d-none text-muted fs-6"><?php echo Text::_('PLG_CFI_IMPORT'); ?>: <span id="import-current-article-title"></span></div>
		</div>
		<div>
			<button class="btn btn-sm btn-danger" disabled id="cfi-stop-task-btn" title="<?php echo Text::_('JCANCEL'); ?>"><i class="fa-solid fa-stop"></i> <span class="visually-hidden"><?php echo Text::_('JCANCEL'); ?></span></button>
		</div>
	</div>
	<div class="form-check form-switch">
		<input class="form-check-input" type="checkbox" value="1" id="progress-switch-convert-cp" role="switch"
			   name="cficonvert" switch checked aria-checked="true">
		<label class="form-check-label fs-6" for="progress-switch-convert-cp">
			<?php
			echo Text::sprintf('PLG_CFI_CB_UTF_CONVERT', $params['params.cp']); ?>
		</label>
	</div>
</div>

<?php echo HTMLHelper::_('uitab.startTabSet', 'cfi', ['active' => 'export', 'recall' => true, 'breakpoint' => 992]); ?>

<?php echo HTMLHelper::_('uitab.addTab', 'cfi', 'export', Text::_('PLG_CFI_EXPORT')); ?>


<div class="row">
    <div class="col-12 col-lg-6 p-3">
        <p class="mb-1"><strong><?php echo Text::_('JCATEGORIES');?>:</strong> <?php echo $categories_count ?? Text::_('JALL');?>. <strong><?php echo Text::_('JGLOBAL_ARTICLES');?>:</strong> <?php echo $total_items;?> </p>
        <?php if(!$categories_count || $categories_count > 1): ?>
            <div class="alert alert-info"><small><?php echo Text::_('PLG_CFI_EXPORT_CUSTOM_FIELDS_DISCLAIMER');?></small></div>
        <?php endif; ?>
        <?php if(!empty($total_items)): ?>
            <div class="d-flex mb-3">
                <button class="btn btn-primary btn-lg me-2" id="cfi-export-btn"><?php echo Text::_('PLG_CFI_EXPORT');?></button>
                <a href="" class="btn btn-success btn-lg d-none" id="cfi-export-download-btn"><?php echo Text::_('PLG_CFI_EXPORT_DOWNLOAD_BTN');?></a>
            </div>
        <?php endif; ?>
		<div class="export-params border p-2">
			<h3><?php echo Text::_('PLG_CFI_EXPORT_PARAMS_LABEL');?></h3>
			<?php if(!empty($params)) :?>
				<div class="form-check form-check-inline">
					<input class="form-check-input" type="checkbox" value="<?php echo (string)$use_tags;?>" name="use_tags" id="cfi_export_params_use_tags_checkbox" <?php echo $use_tags ? 'checked' : '';?>>
					<label class="form-check-label" for="cfi_export_params_use_tags_checkbox">
						<?php echo Text::_('PLG_CFI_EXPORT_PARAMS_USE_TAGS');?>
					</label>
				</div>
				<?php if($use_custom_fields):?>
					<div class="form-check form-check-inline">
						<input class="form-check-input" type="checkbox" value="<?php echo (string)$use_custom_fields;?>" name="use_custom_fields" id="cfi_export_params_custom_fields_checkbox" <?php echo $use_custom_fields ? 'checked' : '';?>>
						<label class="form-check-label" for="cfi_export_params_custom_fields_checkbox">
							<?php echo Text::_('PLG_CFI_EXPORT_PARAMS_USE_CUSTOM_FIELDS');?>
						</label>
					</div>
				<?php endif;?>

			<?php endif; ?>
			<?php if(!empty($filter)) :?>
			<h5 class="mt-3"><?php echo Text::_('PLG_CFI_EXPORT_FILTER_LABEL');?></h5>
				<ul class="list-group list-group-flush">
					<?php if(!empty($filter['filter.search'])): ?>
						<li class="list-group-item">
							<?php echo Text::_('COM_CONTENT_FILTER_SEARCH_LABEL');?>: <?php echo $filter['filter.search'];?>
						</li>
					<?php endif;?>

					<?php if(!empty($filter['filter.featured'])): ?>
						<li class="list-group-item">
							<?php echo Text::_('JFEATURED');?>: <?php echo Text::_('JYES');?>
						</li>
					<?php endif;?>

					<?php if(!empty($filter['filter.published'])): ?>
						<li class="list-group-item">
							<?php echo Text::_('JPUBLISHED');?>: <?php echo Text::_('JYES');?>
						</li>
					<?php endif;?>

					<?php if(!empty($filter['filter.category_id'])): ?>
						<li class="list-group-item">
							<?php echo Text::_('JCATEGORIES');?>: <?php echo implode(', ', $filter['filter.category_id']);?>
						</li>
					<?php endif;?>

					<?php if(!empty($filter['filter.tag'])): ?>
						<li class="list-group-item">
							<?php echo Text::_('JTAG');?>: <?php

							echo implode(' ', array_map(function($item) {
								return "<span class='badge bg-primary'>$item</span>";
							}, $filter['filter.tag']));
							?>
						</li>
					<?php endif;?>
					<?php if(!empty($filter['filter.category_id'])): ?>
						<li class="list-group-item">
							<?php echo Text::_('JGLOBAL_LIST_LIMIT');?>: <?php echo $filter['list.limit'];?>
						</li>
					<?php endif;?>
				</ul>
			<?php endif; ?>
		</div>
    </div>
	<div class="col-12 <?php echo ($use_custom_fields ? 'col-lg-3' : 'col-lg-6');?> p-3">
		<h5><?php echo Text::_('PLG_CFI_EXPORT_ARTICLE_PROPS_LABEL');?></h5>

		<?php
		echo Select::genericlist(
				data:$article_fields,
				name:'cfi_export_article_props[]',
				attribs: 'multiple="multiple" class="form-select h-100"',
				optKey: 'value',
				optText: 'text',
				selected: $params['params.article_props'],
				idtag: 'cfi_export_article_props');
		?>
	</div>
	<?php if ($use_custom_fields): ?>
	<div class="col-12 col-lg-3 p-3">
		<h5><?php echo Text::_('PLG_CFI_EXPORT_ARTICLE_FIELDS_LABEL');?> <?php echo !empty(count($jcfields)) ? '(' . count($jcfields) . ')' : ''; ?></h5>
		<?php
		echo Select::genericlist(
				data:$jcfields,
				name:'cfi_export_article_fields[]',
				attribs: 'multiple="multiple" class="form-select h-100"',
				optKey: 'value',
				optText: 'text',
				selected: [],
				idtag: 'cfi_export_article_fields');
		?>
	</div>
	<?php endif; ?>
</div>

<?php echo HTMLHelper::_('uitab.endTab'); ?>
<?php echo HTMLHelper::_('uitab.addTab', 'cfi', 'import', Text::_('PLG_CFI_IMPORT')); ?>
    <div class="row">
        <div class="col-12 col-lg-6">
            <div id="cfi-import-upload-file-info" class="d-none mb-2">
                <span class="badge bg-primary"><?php echo Text::_('PLG_CFI_IMPORT_UPLOADED_FILE_INFO_FILENAME');?></span><span class="badge bg-info" id="cfi-import-upload-file-info-filename"></span>
                <span class="badge bg-primary"><?php echo Text::_('PLG_CFI_IMPORT_UPLOADED_FILE_INFO_LINES');?></span><span class="badge bg-info" id="cfi-import-upload-file-info-lines"></span>
            </div>
            <div class="">
                <button type="button" id="cfi-import-btn" data-task-id="" class="btn btn-lg btn-primary d-none"><?php echo Text::_('PLG_CFI_IMPORT');?></button>
            </div>
            <div class="row row-cols-2 row-cols-xl d-none" id="cfi-import-progress-data-wrapper">
                <div class="col d-flex mb-2"><?php echo Text::_('PLG_CFI_IMPORT_CFI_IMPORT_PROGRESS_DATA_CONTINUES');?>: <span class="badge bg-primary ms-auto" id="cfi-import-progress-data-continues">0</span></div>
                <div class="col d-flex mb-2"><?php echo Text::_('PLG_CFI_IMPORT_CFI_IMPORT_PROGRESS_DATA_ERRORS');?>: <span class="badge bg-danger ms-auto" id="cfi-import-progress-data-errors">0</span></div>
                <div class="col-12"><hr class="hr"></div>
                <div class="col d-flex mb-2"><?php echo Text::_('PLG_CFI_IMPORT_CFI_IMPORT_PROGRESS_DATA_INSERTS');?>: <span class="badge bg-primary ms-auto" id="cfi-import-progress-data-inserts">0</span></div>
                <div class="col d-flex mb-2"><?php echo Text::_('PLG_CFI_IMPORT_CFI_IMPORT_PROGRESS_DATA_UPDATES');?>: <span class="badge bg-primary ms-auto" id="cfi-import-progress-data-updates">0</span></div>
            </div>
            <details class="d-none" style="max-height: 200px;"><summary><?php echo Text::_('PLG_CFI_IMPORT_CFI_IMPORT_PROGRESS_DATA_ERROR_LIST');?></summary>
                <div id="cfi-import-progress-data-error-list" class="d-block overflow-y-scroll p-2" style="max-height: 150px;">

                </div>
            </details>

        </div>
        <div class="col-12 col-lg-6">
            <?php echo LayoutHelper::render('plugins.system.cfi.upload',[]);?>
        </div>
    </div>


<?php echo HTMLHelper::_('uitab.endTab'); ?>
