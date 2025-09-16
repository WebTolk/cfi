<?php
/**
 * @package       System - CFI
 * @version       2.0.0
 * @Author        Sergey Tolkachyov, https://web-tolk.ru
 * @copyright     Copyright (C) 2024 Sergey Tolkachyov
 * @license       GNU/GPL http://www.gnu.org/licenses/gpl-3.0.html
 * @since         1.0.0
 */

namespace Joomla\Plugin\System\Cfi\Extension;

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\Button\BasicButton;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

use function defined;

defined('_JEXEC') or die;


class Cfi extends CMSPlugin implements SubscriberInterface
{

    use DatabaseAwareTrait;

    /**
     * Load the language file on instantiation.
     *
     * @var    bool
     *
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    // UTF BOM signature
    private array $BOM = [
        "\xEF\xBB\xBF", // UTF-8
        "п»ї", // UTF-8 OO
    ];

    private $app;
    private $appConfig;
    private $doc;
    private $user;
    private $file = null;
    private $cp;
    private $fieldPlugins;
    private $isAdmin;

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
//        $this->initConstruct();
    }

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterDispatch' => 'onAfterDispatch',
            'onAjaxCfi'       => 'onAjaxCfi',
            //            'onRadicalMartGetProductFieldXml'    => 'onRadicalMartGetProductFieldXml',
            //            'onRadicalMartGetProductsFieldValue' => 'onRadicalMartGetProductsFieldValue',
            //            'onRadicalMartGetProductFieldValue'  => 'onRadicalMartGetProductFieldValue',
            //            'onRadicalMartAfterGetFieldForm'     => 'onRadicalMartAfterGetFieldForm',
        ];
    }


//    private function initConstruct($ajax = false)
//    {
//        if (!$this->isAdmin) {
//            return;
//        }
//
//            $this->getApplication()Config = $this->getApplication()->getConfig();
//            $user            = $this->getApplication()->getIdentity();
//            $this->db        = Factory::getContainer()->get('DatabaseDriver');
//
//
//        $this->doc = Factory::getDocument();
//
//        if ($ajax) {
//            $option = $this->getApplication()->getInput()->get('option');
//            $view   = $this->getApplication()->getInput()->get('view');
//            if (!($option == 'com_content' && (in_array($view, ['articles', 'featured', ''])))) {
//                return;
//            }
//        } else {
//            $this->doc->addScript(URI::root(true) . '/plugins/system/cfi/assets/cfi.js');
//            $this->doc->addStyleSheet(URI::root(true) . '/plugins/system/cfi/assets/cfi.css');
//        }
//
//        $this->user = $user->id . ':' . $user->username;
//
//        $this->cp = $this->params->get('cp', 'CP1251');
//
//        $this->fieldPlugins = [
//            'imagelist' => 0,
//            'integer' => 0,
//            'list' => 0,
//            'sql' => 0,
//            'usergrouplist' => 0
//        ];
//        $plugins = PluginHelper::getPlugin('fields');
//        foreach ($plugins as $key => $plugin) {
//            $plugins[$plugin->name] = $plugin;
//            unset($plugins[$key]);
//            $plugins[$plugin->name]->params = new Registry($plugins[$plugin->name]->params);
//        }
//        foreach (array_keys($this->fieldPlugins) as $pluginName) {
//            $multiple = $plugins[$pluginName]->params->get('multiple', -1);
//            if ($multiple >= 0) {
//                $this->fieldPlugins[$pluginName] = (int)$multiple;
//            }
//        }
//
//        BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');
//        BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_content/models/', 'ContentModel');
//    }

    /**
     *  Add button to toolbar
     *
     * @since 2.0.0
     */
    public function onAfterDispatch()
    {
        if (!$this->getApplication()->isClient('administrator')) {
            return;
        }

        $option = $this->getApplication()->getInput()->get('option');
        $view   = $this->getApplication()->getInput()->get('view');

        if (!($option == 'com_content' && (in_array($view, ['articles', 'featured'])))) {
            return;
        }
        $wa = $this->getApplication()->getDocument()->getWebAssetManager();
        $wa->useScript('joomla.dialog-autocreate');
        $toolbar = $this->getApplication()->getDocument()->getToolbar('toolbar');


        $uri  = new Uri('index.php');
        $vars = [
            'option'                => 'com_ajax',
            'group'                 => 'system',
            'plugin'                => 'cfi',
            'format'                => 'html',
            'tmpl'                  => 'component',
            Session::getFormToken() => '1',
        ];
        $uri->setQuery($vars);

        $modal_params = [
            'popupType'  => 'iframe',
            'textHeader' => 'ЫЫМПОРТ',
            'width'      => '40vw',
            'height'     => '40vh',
            'src'        => $uri->toString(),
        ];
        $button       = (new BasicButton('cfi-import', Text::_('Ымпорт')))
            ->attributes(
                [
                    'data-joomla-dialog' => htmlspecialchars(
                        json_encode($modal_params, JSON_UNESCAPED_SLASHES),
                        ENT_QUOTES,
                        'UTF-8'
                    ),
                ]
            );
        $toolbar->appendButton($button);
    }

//    public function onBeforeRender()
//    {
//        if (!$this->isAdmin || $this->doc->getType() != 'html') {
//            return;
//        }
//
//        $option = $this->getApplication()->getInput()->get('option');
//        $view   = $this->getApplication()->getInput()->get('view');
//        if (!($option == 'com_content' && (in_array($view, ['articles', 'featured', ''])))) {
//            return;
//        }
//
//        $toolbar = new FileLayout(
//            'toolbar_j' . Version::MAJOR_VERSION,
//            Path::clean(JPATH_PLUGINS . '/system/cfi/layouts')
//        );
//        ToolBar::getInstance('toolbar')->appendButton('Custom', $toolbar->render([]), 'cfi');
//
//        return true;
//    }

//    public function onAfterRender()
//    {
//        if (!$this->isAdmin || $this->doc->getType() != 'html') {
//            return;
//        }
//
//        $option = $this->getApplication()->getInput()->get('option');
//        $view   = $this->getApplication()->getInput()->get('view');
//        if (!($option == 'com_content' && (in_array($view, ['articles', 'featured', ''])))) {
//            return;
//        }
//
//        $html = $this->getApplication()->getBody();
//
//        if (strpos($html, '</head>') !== false) {
//            [$head, $content] = explode('</head>', $html, 2);
//        } else {
//            $content = $html;
//        }
//
//        if (empty($content)) {
//            return false;
//        }
//
//        $query = $this->db->getQuery(true)
//            ->select('id, title')
//            ->from('#__categories')
//            ->where('extension = "com_content"')
//            ->order('title');
//        $this->db->setQuery($query);
//        try {
//            $categories = $this->db->loadObjectList();
//        } catch (Exception $e) {
//            $categories = [];
//        }
//
//        $well = new FileLayout('well_j' . Version::MAJOR_VERSION, Path::clean(JPATH_PLUGINS . '/system/cfi/layouts'));
//        $matches = [];
//        preg_match('#id="j-main-container" (\w+)(.*?)>#i', $content, $matches);
//        if ($matches && $matches[0]) {
//            $wellParams = [
//                'cp' => $this->cp,
//                'categories' => $categories,
//                'showdesc' => $this->params->get('showdesc', 1)
//            ];
//            $content = str_replace($matches[0], $matches[0] . $well->render($wellParams), $content);
//            $html = isset($head) ? ($head . '</head>' . $content) : $content;
//            $this->getApplication()->setBody($html);
//            return true;
//        }
//
//        return;
//    }

    public function onAjaxCfi(AjaxEvent $event)
    {
        dump($event);
//        Log::addLogger(['textfile' => 'cfi.php', 'text_entry_format' => "{DATETIME}\t{PRIORITY}\t{MESSAGE}"], Log::ALL);
//
//        $this->initConstruct(true);
//
//        $state = $this->getApplication()->getInput()->get('cfistate', '');
//
//        if (!Session::checkToken($state == 'download' ? 'get' : 'post')) {
//            $data = [
//                'result' => Text::_('JINVALID_TOKEN'),
//                'user' => $this->user,
//                'file' => $this->getApplication()->getInput()->files->getArray(),
//                'get' => $this->getApplication()->getInput()->get->getArray(),
//                'post' => $this->getApplication()->getInput()->post->getArray()
//            ];
//            Log::add(json_encode($data), Log::ERROR);
//            $this->printJson($data['result']);
//        }
//
//        if ($state == 'import') {
//            $this->checkFile($this->getApplication()->getInput()->files->get('cfifile'));
//            $this->importData();
//        }
//
//        if ($state == 'export') {
//            $this->exportData();
//        }
//
//        if ($state == 'download') {
//            $this->file = $this->getApplication()->getInput()->get('f', '');
//            if ($this->file) {
//                $this->file = Path::clean(Factory::getContainer()->get('config')->get('tmp_path') . '/' . urldecode($this->file));
//                $this->fileDownload($this->file);
//                @unlink($this->file);
//            }
//        }
    }

//    private function printJson($message = '', $result = false, $custom = [])
//    {
//        $custom['result'] = $result;
//        $custom['message'] = $message;
//        echo json_encode($custom);
//        exit;
//    }

//    private function checkFile($file)
//    {
//        $data = [
//            'result' => '',
//            'user' => $this->user,
//            'file' => $file
//        ];
//
//        if (is_array($file) && count($file)) {
//            if ($file['error'] != 0) {
//                $data['result'] = Text::_('PLG_CFIfile_ERROR');
//                Log::add(json_encode($data), Log::ERROR);
//                $this->printJson($data['result']);
//            }
//
//            if (!$file['size']) {
//                $data['result'] = Text::_('PLG_CFIfile_SIZE');
//                Log::add(json_encode($data), Log::ERROR);
//                $this->printJson($data['result']);
//            }
//
//            if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
//                $data['result'] = Text::_('PLG_CFIfile_TYPE');
//                Log::add(json_encode($data), Log::ERROR);
//                $this->printJson($data['result']);
//            }
//
//            $this->file = Path::clean($this->getApplication()Config->get('tmp_path') . '/cfi_' . date('Y-m-d-H-i-s') . '.csv');
//            if (!@move_uploaded_file($file['tmp_name'], $this->file)) {
//                $data['result'] = Text::_('PLG_CFIfile_MOVE');
//                Log::add(json_encode($data), Log::ERROR);
//                $this->printJson($data['result']);
//            }
//
//            return true;
//        }
//
//        $data['result'] = Text::_('PLG_CFIfile_NOTHING');
//        Log::add(json_encode($data), Log::ERROR);
//        $this->printJson($data['result']);
//    }
//
//    private function importData()
//    {
//        // log template
//        $data = [
//            'result' => '',
//            'user' => $this->user,
//            'file' => $this->file
//        ];
//
//        // get categories
//        $categories = $this->getCategories();
//        if (!$categories) {
//            $data['result'] = Text::_('PLG_CFI_IMPORT_GET_CATEGORIES');
//            Log::add(json_encode($data), Log::ERROR);
//            $this->printJson($data['result']);
//        }
//
//        // get file content
//        $content = trim(file_get_contents($this->file));
//
//        // convert to UTF-8
//        $isConvert = (int) $this->getApplication()->getInput()->get('cficonvert', 0);
//
//        if ($isConvert > 0) {
//            $content = mb_convert_encoding($content, 'UTF-8', $this->cp);
//        }
//
//        // unset utf-8 bom
//        $content = str_replace($this->BOM, '', $content);
//
//        // line separator definition
//        $rowDelimiter = "\r\n";
//        if (false === strpos($content, "\r\n")) {
//            $rowDelimiter = "\n";
//        }
//
//        // get lines array
//        $lines = explode($rowDelimiter, trim($content));
//        $lines = array_filter($lines);
//        $lines = array_map('trim', $lines);
//
//        if (count($lines) < 2) {
//            $data['result'] = Text::_('PLG_CFI_IMPORT_EMPTY');
//            Log::add(json_encode($data), Log::ERROR);
//            $this->printJson($data['result']);
//        }
//
//        // get columns
//        $columns = str_getcsv($lines[0], ';');
//
//        if ((array_search('articleid', $columns) === false) || (array_search('articletitle', $columns) === false)) {
//            $data['result'] = Text::_('PLG_CFI_IMPORT_NO_COLUMN');
//            Log::add(json_encode($data), Log::ERROR);
//            $this->printJson($data['result']);
//        }
//        unset($lines[0]);
//
//        // set reserved name's of columns
//        $reservedColumns = [
//            'articleid',
//            'articlecat',
//            'articletitle',
//            'articlelang',
//            'articleintrotext',
//            'articlefulltext'
//        ];
//
//        // data processing
//        $errors = [];
//        $inserts = 0;
//        $updates = 0;
//        $continues = 0;
//
//        $fieldModel = BaseDatabaseModel::getInstance('Field', 'FieldsModel', ['ignore_request' => true]);
//
//        BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_content/models/', 'ContentModel');
//        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_content/tables/');
//        if (Version::MAJOR_VERSION > 3) {
//            Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/forms');
//        } else {
//            Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/models/forms');
//            Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/model/form');
//            Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_content/models/fields');
//            Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_content/model/field');
//        }
//
//        set_time_limit(0);
//
//        foreach ($lines as $strNum => $str) {
//            // get string in file
//            $fieldsData = str_getcsv($str, ';');
//
//            // check count columns
//            if (count($fieldsData) != count($columns)) {
//                $errors[$strNum + 1] = Text::_('PLG_CFI_IMPORT_COLUMN_EXCEPT');
//                $continues++;
//                continue;
//            }
//
//            // column association
//            $articleData = [];
//            foreach ($columns as $key => $column) {
//                if (in_array($column, $reservedColumns)) {
//                    $articleData[$column] = $fieldsData[$key];
//                } else {
//                    $fieldsData[$column] = $fieldsData[$key];
//                }
//                unset($fieldsData[$key]);
//            }
//
//            // get missing article values
//            $articleData['articlecat'] = array_key_exists('articlecat', $articleData)
//                && in_array($articleData['articlecat'], $categories) ? $articleData['articlecat'] : $categories[0];
//            $articleData['articlelang'] = array_key_exists('articlelang', $articleData)
//                ? $articleData['articlelang'] : '*';
//            $articleData['articleintrotext'] = array_key_exists('articleintrotext', $articleData)
//                ? $articleData['articleintrotext'] : '';
//            $articleData['articlefulltext'] = array_key_exists('articlefulltext', $articleData)
//                ? $articleData['articlefulltext'] : '';
//
//            // get article instance
//            $model = BaseDatabaseModel::getInstance('Article', 'ContentModel');
//
//            $article = [];
//            $isNewArticle = true;
//            $state = 1;
//            if ($articleData['articleid'] > 0) {
//                //var_dump($articleData['articleid']);
//                $article = $model->getItem((int)$articleData['articleid']);
//                if (empty($article) || !$article->id) {
//                    unset($article);
//                    $state = 0;
//                    $errors[$strNum + 1] = Text::sprintf('PLG_CFI_IMPORT_LOAD_ARTICLE', $articleData['articleid']);
//                    continue;
//                } else {
//                    $isNewArticle = false;
//                    $article = (array)$article;
//                    unset($article[array_key_first($article)]);
//                    if (isset($article['tags'])) {
//                        $article['tags'] = explode(',', $article['tags']->tags);
//                    }
//
//                    // set new data on existing article item
//                    $article['title'] = $articleData['articletitle'];
//                    $article['introtext'] = $articleData['articleintrotext'];
//                    $article['fulltext'] = $articleData['articlefulltext'];
//                }
//            }
//
//            if ($isNewArticle) {
//                //set data on new article item
//                $article['id']         = 0;
//                $article['title']      = $articleData['articletitle'];
//                $article['alias']      = OutputFilter::stringURLSafe($article['title']);
//                $article['introtext']  = $articleData['articleintrotext'];
//                $article['fulltext']   = $articleData['articlefulltext'];
//                $article['catid']      = $articleData['articlecat'];
//                $article['language']   = $articleData['articlelang'];
//                $article['featured']   = 0;
//                $article['created']    = Factory::getDate()->toSql();
//                $article['created_by'] = explode(':', $this->user)[0];
//                $article['state']      = $state;
//                $article['access']     = $this->getApplication()->get('access', 1);
//                $article['metadata']   = [
//                    'robots'     => '',
//                    'author'     => '',
//                    'rights'     => '',
//                    'xreference' => ''
//                ];
//                $article['images']     = [
//                    'image_intro'            => '',
//                    'float_intro'            => '',
//                    'image_intro_alt'        => '',
//                    'image_intro_caption'    => '',
//                    'image_fulltext'         => '',
//                    'float_fulltext'         => '',
//                    'image_fulltext_alt'     => '',
//                    'image_fulltext_caption' => ''
//                ];
//                $article['urls']       = [
//                    'urla'     => false,
//                    'urlatext' => '',
//                    'targeta'  => '',
//                    'urlb'     => false,
//                    'urlbtext' => '',
//                    'targetb'  => '',
//                    'urlc'     => false,
//                    'urlctext' => '',
//                    'targetc'  => ''
//                ];
//                $article['attribs']    = [
//                    'article_layout'           => '',
//                    'show_title'               => '',
//                    'link_titles'              => '',
//                    'show_tags'                => '',
//                    'show_intro'               => '',
//                    'info_block_position'      => '',
//                    'info_block_show_title'    => '',
//                    'show_category'            => '',
//                    'link_category'            => '',
//                    'show_parent_category'     => '',
//                    'link_parent_category'     => '',
//                    'show_associations'        => '',
//                    'show_author'              => '',
//                    'link_author'              => '',
//                    'show_create_date'         => '',
//                    'show_modify_date'         => '',
//                    'show_publish_date'        => '',
//                    'show_item_navigation'     => '',
//                    'show_icons'               => '',
//                    'show_print_icon'          => '',
//                    'show_email_icon'          => '',
//                    'show_vote'                => '',
//                    'show_hits'                => '',
//                    'show_noauth'              => '',
//                    'urls_position'            => '',
//                    'alternative_readmore'     => '',
//                    'article_page_title'       => '',
//                    'show_publishing_option'   => '',
//                    'show_article_options'     => '',
//                    'show_urls_images_backend' => '',
//                    'show_urls_images_fronten' => '',
//                ];
//            }
//
//            // article form
//            $form = $model->getForm($article, true);
//            $errs = [];
//            if (!$form) {
//                foreach ($model->getErrors() as $error) {
//                    $errs[] = ($error instanceof Exception) ? $error->getMessage() : $error;
//                }
//                if (!empty($errors[$strNum + 1])) {
//                    $errors[$strNum + 1] .= '. ' . Text::_('PLG_CFI_IMPORT_SAVE_ARTICLE') . ': ' . implode('; ', $errs);
//                } else {
//                    $errors[$strNum + 1] = Text::_('PLG_CFI_IMPORT_SAVE_ARTICLE') . ': ' . implode('; ', $errs);
//                }
//                unset($model, $article, $errs);
//                $continues++;
//                continue;
//            }
//
//            // save article item
//            $this->getApplication()->getInput()->set('task', 'save');
//            if ($model->save($article) === false) {
//                foreach ($model->getErrors() as $error) {
//                    $errs[] = ($error instanceof Exception) ? $error->getMessage() : $error;
//                }
//                if (!empty($errors[$strNum + 1])) {
//                    $errors[$strNum + 1] .= '. ' . Text::_('PLG_CFI_IMPORT_SAVE_ARTICLE') . ': ' . implode('; ', $errs);
//                } else {
//                    $errors[$strNum + 1] = Text::_('PLG_CFI_IMPORT_SAVE_ARTICLE') . ': ' . implode('; ', $errs);
//                }
//                unset($model, $article, $errs);
//                $continues++;
//                continue;
//            } else {
//                if (!empty($errors[$strNum + 1])) {
//                    $errors[$strNum + 1] .= '. ' . Text::_('PLG_CFI_IMPORT_SAVENEW_ARTICLE');
//                }
//            }
//
//            if ($isNewArticle) {
//                $inserts++;
//
//                // get ID for the new article
//                $article['id'] = $model->getState($model->getName() . '.id');
//            } else {
//                $updates++;
//            }
//
//            // get article custom fields
//            $jsFields = FieldsHelper::getFields('com_content.article', $article, true);
//            foreach ($jsFields as $key => $jsField) {
//                $jsFields[$jsField->name] = $jsField;
//                unset($jsFields[$key]);
//            }
//
//            // save field's values
//            $fieldsErrors = [];
//            foreach ($fieldsData as $fieldName => $fieldValue) {
//                if (array_key_exists($fieldName, $jsFields)) {
//                    if (
//                        $jsFields[$fieldName]->type === 'checkboxes' ||
//                        in_array($jsFields[$fieldName]->type, array_keys($this->fieldPlugins))
//                    ) {
//                        $decode = json_decode($fieldValue, true);
//                        $fieldValue = json_last_error() === JSON_ERROR_NONE ? $decode : [$fieldValue];
//                    } elseif (strpos($fieldValue, 'array::') === 0) {
//                        $fieldValue = json_decode(explode('::', $fieldValue, 2)[1]);
//                    }
//                    if (!$fieldModel->setFieldValue($jsFields[$fieldName]->id, $article['id'], $fieldValue)) {
//                        $fieldsErrors[] = $fieldName;
//                    }
//                }
//            }
//            if ($fieldsErrors) {
//                $errors[$strNum + 1] = Text::sprintf('PLG_CFI_IMPORT_SAVE_FIELDS', implode(', ', $fieldsErrors));
//            }
//
//            // destroy article instance
//            unset($article, $jsFields);
//        }
//
//        // show result
//        $data['result'] = Text::sprintf('PLG_CFI_RESULT', $inserts + $updates, $inserts, $updates) .
//            ($errors ? '<br>' . Text::sprintf('PLG_CFI_RESULT_ERROR', $continues) : '');
//        if ($errors) {
//            $data['errors'] = $errors;
//        } else {
//            unlink($this->file);
//        }
//        Log::add(json_encode($data), Log::INFO);
//        $this->printJson($data['result'], true);
//    }
//
//    private function getCategories()
//    {
//        $query = $this->db->getQuery(true)
//            ->select('id')
//            ->from('#__categories')
//            ->where('extension = "com_content"')
//            ->order('id');
//        $this->db->setQuery($query);
//        try {
//            return $this->db->loadColumn();
//        } catch (Exception $e) {
//            return false;
//        }
//    }
//
//    private function exportData()
//    {
//        // log template
//        $data = [
//            'result' => '',
//            'user' => $this->user
//        ];
//
//        // get id category
//        $catid = (int)$this->getApplication()->getInput()->get('cficat', 0);
//        if (!$catid) {
//            $data['result'] = Text::_('PLG_CFI_EXPORT_NO_CATEGORY');
//            Log::add(json_encode($data), Log::ERROR);
//            $this->printJson($data['result']);
//        }
//
//        // get articles
//        $query = $this->db->getQuery(true)
//            ->select('id, title, language, introtext, `fulltext`')
//            ->from('#__content')
//            ->where('state >= 0')
//            ->where('catid = ' . (int)$catid)
//            ->order('id');
//        $this->db->setQuery($query);
//        try {
//            $articles = $this->db->loadObjectList();
//        } catch (Exception $e) {
//            $data['result'] = Text::_('PLG_CFI_EXPORT_GET_CONTENT');
//            Log::add(json_encode($data), Log::ERROR);
//            $this->printJson($data['result']);
//        }
//
//        if (!$articles) {
//            $data['result'] = Text::_('PLG_CFI_EXPORT_EMPTY_CONTENT');
//            Log::add(json_encode($data), Log::ERROR);
//            $this->printJson($data['result']);
//        }
//
//        // file handler
//        $this->file = Path::clean($this->getApplication()Config->get('tmp_path') . '/cfi_export_' . date('Y-m-d-H-i-s') . '.csv');
//        if (($fileHandle = fopen($this->file, 'w')) === false) {
//            $data['result'] = Text::_('PLG_CFI_EXPORTfile_CREATE');
//            Log::add(json_encode($data), Log::ERROR);
//            $this->printJson($data['result']);
//        }
//
//        // make columns
//        $columns = [
//            'articleid',
//            'articlecat',
//            'articletitle',
//            'articlelang',
//            'articleintrotext',
//            'articlefulltext'
//        ];
//        $jsFields = FieldsHelper::getFields('com_content.article', $articles[0], true);
//        foreach ($jsFields as $jsField) {
//            $columns[] = $jsField->name;
//        }
//        fputcsv($fileHandle, $columns, ';');
//
//        // processing
//        foreach ($articles as $article) {
//            $outItem = [];
//            $outItem[] = $article->id;
//            $outItem[] = $catid;
//            $outItem[] = str_replace(["\n", "\r"], ' ', $article->title);
//            $outItem[] = str_replace(["\n", "\r"], ' ', $article->language);
//            $outItem[] = str_replace(["\n", "\r"], ' ', $article->introtext);
//            $outItem[] = str_replace(["\n", "\r"], ' ', $article->fulltext);
//
//            $jsFields = FieldsHelper::getFields('com_content.article', $article, true);
//            foreach ($jsFields as $jsField) {
//                if (
//                    $jsField->type === 'checkboxes' ||
//                    in_array($jsField->type, array_keys($this->fieldPlugins))
//                ) {
//                    $outItem[] =
//                        is_countable($jsField->rawvalue) && count($jsField->rawvalue) > 1
//                            ? json_encode($jsField->rawvalue)
//                            : (is_array($jsField->rawvalue) ? $jsField->rawvalue[0] : $jsField->rawvalue);
//                } elseif (is_array($jsField->rawvalue)) {
//                    $outItem[] = 'array::' . json_encode($jsField->rawvalue);
//                } else {
//                    $outItem[] = str_replace(["\n", "\r"], ' ', $jsField->rawvalue);
//                }
//            }
//            fputcsv($fileHandle, $outItem, ';');
//        }
//
//        // save file
//        fclose($fileHandle);
//        unset($articles, $jsFields);
//
//        // convert
//        if ((bool) $this->getApplication()->getInput()->get('cficonvert', false)) {
//            $contentIn = file_get_contents($this->file);
//            if ($contentIn !== false) {
//                $content = mb_convert_encoding($contentIn, $this->cp, 'UTF-8');
//                if (!$content) {
//                    $data['result'] = Text::_('PLG_CFI_EXPORT_ERROR_CONVERT');
//                    $date['file'] = $this->file;
//                    Log::add(json_encode($data), Log::ERROR);
//                    $this->printJson($data['result']);
//                }
//                if (file_put_contents($this->file, $content) === false) {
//                    $data['result'] = Text::_('PLG_CFI_EXPORT_ERROR_AFTER_CONVERT');
//                    $date['file'] = $this->file;
//                    Log::add(json_encode($data), Log::ERROR);
//                    $this->printJson($data['result']);
//                }
//            } else {
//                $data['result'] = Text::_('PLG_CFI_EXPORT_ERROR_BEFORE_CONVERT');
//                $date['file'] = $this->file;
//                Log::add(json_encode($data), Log::ERROR);
//                $this->printJson($data['result']);
//            }
//        }
//
//        // return result
//        $data['result'] = Text::_('PLG_CFI_EXPORT_SUCCESS');
//        $date['file'] = $this->file;
//        Log::add(json_encode($data), Log::INFO);
//        $this->printJson($data['result'], true, ['f' => urlencode(pathinfo($this->file, PATHINFO_BASENAME))]);
//
//        exit;
//    }
//
//    private function fileDownload($file)
//    {
//        set_time_limit(0);
//        if (file_exists($file)) {
//            if (ob_get_level()) {
//                ob_end_clean();
//            }
//            header('Content-Description: File Transfer');
//            header('Content-Type: text/csv');
//            header('Content-Disposition: attachment; filename=' . basename($file));
//            header('Content-Transfer-Encoding: binary');
//            header('Expires: 0');
//            header('Cache-Control: must-revalidate');
//            header('Pragma: public');
//            header('Content-Length: ' . filesize($file));
//            return (bool) readfile($file);
//        } else {
//            return false;
//        }
//    }
}

