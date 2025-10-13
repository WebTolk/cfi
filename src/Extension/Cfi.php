<?php
/**
 * @package    System - CFI
 * @version       2.0.0
 * @Author        Sergey Tolkachyov, https://web-tolk.ru
 * @copyright     Copyright (C) 2024 Sergey Tolkachyov
 * @license       GNU/GPL http://www.gnu.org/licenses/gpl-3.0.html
 * @since         1.0.0
 */

namespace Joomla\Plugin\System\Cfi\Extension;

use Exception;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Helper\MediaHelper;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\Button\BasicButton;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Dispatcher;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Path;
use Joomla\Filter\OutputFilter;
use Joomla\Registry\Registry;

use function defined;
use function fputcsv;
use function is_file;

defined('_JEXEC') or die;


final class Cfi extends CMSPlugin implements SubscriberInterface
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

    private $user;
    private ?string $file;
    private string $cp;
    private array $fieldPlugins;

//    private array $tableHeaders = [];

    /**
     * @var mixed
     * @since version
     */
    private mixed $config;
    /**
     * @var string
     * @since 2.0.0
     */
    private string $task_id;
    /**
     * @var string
     * @since 2.0.0
     */
    private string $task_id_file;

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->config = Factory::getContainer()->get('config');
        $this->file   = Path::clean($this->config->get('tmp_path') . '/' . (new Date)->toUnix() . '.csv');

        $this->task_id_file = $this->config->get('tmp_path') . '/cfi_task_%s.json';

        $this->fieldPlugins = [
            'imagelist'     => 0,
            'integer'       => 0,
            'list'          => 0,
            'sql'           => 0,
            'usergrouplist' => 0,
        ];


        $user       = Factory::getApplication()->getIdentity();
        $this->user = $user->id . ':' . $user->username;

        $this->cp = $this->params->get('cp', 'CP1251');

        $plugins = PluginHelper::getPlugin('fields');
        foreach ($plugins as $key => $plugin) {
            $plugins[$plugin->name] = $plugin;
            unset($plugins[$key]);
            $plugins[$plugin->name]->params = new Registry($plugins[$plugin->name]->params);
        }
        foreach (array_keys($this->fieldPlugins) as $pluginName) {
            $multiple = $plugins[$pluginName]->params->get('multiple', -1);
            if ($multiple >= 0) {
                $this->fieldPlugins[$pluginName] = (int)$multiple;
            }
        }
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
            'action'                => 'viewModal',
            Session::getFormToken() => '1',
        ];
        $uri->setQuery($vars);

        $modal_params = [
            'popupType'  => 'iframe',
            'textHeader' => Text::_('PLG_CFI_BUTTON'),
            'width'      => '80vw',
//            'height'     => '60vh',
            'src'        => $uri->toString(),
        ];
        $button       = (new BasicButton('cfi-import', Text::_('PLG_CFI_BUTTON')))
            ->attributes(
                [
                    'data-joomla-dialog' => htmlspecialchars(
                        json_encode($modal_params, JSON_UNESCAPED_SLASHES),
                        ENT_QUOTES,
                        'UTF-8'
                    ),
                    'title'              => Text::_('PLG_CFI_BUTTON_TITLE'),
                ]
            )
            ->icon('fa-solid fa-file-export');
        $toolbar->appendButton($button);
    }

    public function onAjaxCfi(AjaxEvent $event)
    {
        /**
         * Тут разрулить по
         * action = view
         * action = import
         * action = export
         */

        if (!$this->getApplication()->isClient('administrator')) {
            return;
        }

        Session::checkToken('get') or die(Text::_('JINVALID_TOKEN'));

        $action  = $this->getApplication()->getInput()->getString('action', 'viewModal');
        $task_id = $this->getApplication()->getInput()->getString('task_id', '');

        switch ($action) {
            case 'start_task':
                $task_type = $this->getApplication()->getInput()->getString('task_type', 'export');
                $this->startTask($task_id, $task_type);
                break;
            case 'delete_task_file':
                $this->deleteTaskFile($task_id);
                break;
            case 'check_status':
                $result = $this->checkTaskStatus($task_id);
                $event->addResult($result);
                break;
            case 'uploadCSV':
                  echo $this->uploadCSV();
                break;
            case 'import_articles':
                $this->importArticles($task_id);
                break;
            case 'export_articles':
                    $this->exportArticles($task_id);
                break;
            case 'viewModal':
            default:
                $model         = $this->getApplication()->bootComponent('com_content')->getMVCFactory()->createModel(
                    'Articles'
                );
                $categories    = $model->getState('filter.category_id');
                $tag_ids       = $model->getState('filter.tag');
                $article_props = $this->params->get(
                    'article_fields',
                    ['id', 'title', 'language', 'introtext', 'fulltext']
                );
                $export_data   = [
                    'total_items'      => $model->getTotal(),
                    'categories_count' => (!empty($categories) ? count($categories) : $categories),
                    'filter'           => [
                        'filter.search'      => $model->getState('filter.search'),
                        'filter.featured'    => $model->getState('filter.featured'),
                        'filter.published'   => $model->getState('filter.published'),
                        'filter.category_id' => !empty($categories) ? $this->getCategoryTitles($categories) : [],
                        'filter.tag'         => !empty($tag_ids) ? $this->getTagTitles($tag_ids) : [],
                        'list.limit'         => $model->getState('list.limit'),
                    ],
                    'params'           => [
                        'params.use_tags'          => $this->params->get('use_tags', 0),
                        'params.use_custom_fields' => $this->params->get('use_custom_fields', 0),
                        'params.article_props'     => $article_props,
                        'params.cp'                => $this->params->get('cp', 'CP1251'),
                    ],
                ];

                $this->getApplication()->getLanguage()->load('com_content');
                $this->getApplication()->getLanguage()->load('plg_system_cfi', JPATH_ADMINISTRATOR);
                echo LayoutHelper::render(
                    'default',
                    ['export_data' => $export_data],
                    JPATH_SITE . '/layouts/plugins/system/cfi'
                );

                break;
        }


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


    /**
     * Create a temporary json file for export task
     *
     * @param   string  $task_id
     * @param   string  $task_type
     *
     *
     * @since 2.0.0
     */
    private function startTask(string $task_id, string $task_type)
    {
        $task_file = $this->getTaskIdFile($task_id);
        $task_data = ['current' => 0, 'total' => 0, 'type' => $task_type];
        file_put_contents($task_file, json_encode($task_data));
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
//            $this->file = Path::clean($this->getApplication()Factory::getContainer()->get('config')->get('tmp_path') . '/cfi_' . date('Y-m-d-H-i-s') . '.csv');
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
    /**
     * @param   string  $task_id
     *
     *
     * @throws Exception
     * @since 1.0.0
     */
    private function importArticles(string $task_id)
    {
//        $this->file = $this->config->get('tmp_path', JPATH_SITE.'/tmp').'/'.$task_id.'.csv';
        $this->file   = Path::clean($this->config->get('tmp_path') . '/' . $task_id . '.csv');
        // log template
        $log_data = [
            'result' => '',
            'user' => $this->user,
            'file' => $this->file
        ];

        // get categories
        $categories = $this->getCategories();
        if (!$categories) {
            $log_data['result'] = Text::_('PLG_CFI_IMPORT_GET_CATEGORIES');
            $this->saveToLog($log_data, Log::ERROR);
            echo new JsonResponse([], $log_data['result'], true);
            return false;
        }

        // convert to UTF-8
        $isConvert = (int) $this->getApplication()->getInput()->get('cficonvert', 0);

        if ($isConvert > 0) {
            $converted = $this->convertFile($this->cp, 'UTF-8');
//            $content = mb_convert_encoding($content, 'UTF-8', $this->cp);
        }

        // get file content
        $content = trim(file_get_contents($this->file));

        // unset utf-8 bom
        $content = str_replace($this->BOM, '', $content);

        // line separator definition
        $rowDelimiter = "\r\n";
        if (false === strpos($content, "\r\n")) {
            $rowDelimiter = "\n";
        }

        // get lines array
        $lines = explode($rowDelimiter, trim($content));
        $lines = array_filter($lines);
        $lines = array_map('trim', $lines);

        if (count($lines) < 2) {
            $log_data['result'] = Text::_('PLG_CFI_IMPORT_EMPTY');
            $this->saveToLog($log_data, Log::ERROR);
            echo new JsonResponse([],Text::_('PLG_CFI_IMPORT_EMPTY'),true);
            return false;
        }

        // get columns
        $columns = str_getcsv($lines[0], ';', '"', '\\');

        if ((array_search('articleid', $columns) === false) || (array_search('articletitle', $columns) === false)) {
            $log_data['result'] = Text::_('PLG_CFI_IMPORT_NO_COLUMN');
            $this->saveToLog($log_data, Log::ERROR);
            echo new JsonResponse([],Text::_('PLG_CFI_IMPORT_NO_COLUMN'),true);
            return false;
        }
        unset($lines[0]);

        // set reserved name's of columns
        $reservedColumns = [
            'articleid',
            'articlecat',
            'articletitle',
            'articlelang',
            'articleintrotext',
            'articlefulltext'
        ];

        // data processing
        $errors = [];
        $inserts = 0;
        $updates = 0;
        $continues = 0;

        $fieldModel = $this->getApplication()
            ->bootComponent('com_fields')
            ->getMVCFactory()
            ->createModel('Field', 'Administrator', ['ignore_request' => true]);

//        BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_content/models/', 'ContentModel');
//        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_content/tables/');
//        if (Version::MAJOR_VERSION > 3) {
            Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/forms');
//        } else {
//            Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/models/forms');
//            Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/model/form');
//            Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_content/models/fields');
//            Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_content/model/field');
//        }

        set_time_limit(0);

        $prepareEvent = AbstractEvent::create(
            'onImportPrepareData',
            [
                'subject'  => $this,
                'columns'  => $columns,
                'articles' => $lines
            ]
        );

        $dispatcher = new Dispatcher();
        PluginHelper::importPlugin('cfi', null, true, $dispatcher);
        $results  = $dispatcher->dispatch($prepareEvent->getName(), $prepareEvent);
        $columns  = $results['columns'];
        $lines = $results['articles'];

        // Save taskId data
        file_put_contents(
            $this->getTaskIdFile($task_id),
            json_encode([
                'total' => count($lines),
                'current' => 0,
                'type' => 'import',
                'status' => 'inprogress',
                'errors_count' => count($errors),
                'errors' => $errors,
                'inserts' => $inserts,
                'updates' => $updates,
                'continues' => $continues,
            ])
        );

        foreach ($lines as $strNum => $str) {
            // get string in file
            $fieldsData = str_getcsv($str, ';', '"','\\');

            // check count columns
            if (count($fieldsData) != count($columns)) {
                $errors[$strNum + 1] = Text::_('PLG_CFI_IMPORT_COLUMN_EXCEPT');
                $continues++;

                // Save taskId data
                file_put_contents(
                    $this->getTaskIdFile($task_id),
                    json_encode([
                        'total' => count($lines),
                        'current' => $strNum,
                        'type' => 'import',
                        'status' => 'inprogress',
                        'errors_count' => count($errors),
                        'errors' => $errors,
                        'inserts' => $inserts,
                        'updates' => $updates,
                        'continues' => $continues,
                    ])
                );

                continue;
            }

            // column association
            $articleData = [];
            foreach ($columns as $key => $column) {
                if (in_array($column, $reservedColumns)) {
                    $articleData[$column] = $fieldsData[$key];
                } else {
                    $fieldsData[$column] = $fieldsData[$key];
                }
                unset($fieldsData[$key]);
            }

            // get missing article values
            $articleData['articlecat'] = array_key_exists('articlecat', $articleData)
                && in_array($articleData['articlecat'], $categories) ? $articleData['articlecat'] : $categories[0];
            $articleData['articlelang'] = array_key_exists('articlelang', $articleData)
                ? $articleData['articlelang'] : '*';
            $articleData['articleintrotext'] = array_key_exists('articleintrotext', $articleData)
                ? $articleData['articleintrotext'] : '';
            $articleData['articlefulltext'] = array_key_exists('articlefulltext', $articleData)
                ? $articleData['articlefulltext'] : '';
file_put_contents(JPATH_SITE.'/tmp/article_data.txt', print_r($articleData, true), FILE_APPEND);
            // get article instance
            $model = $this->getApplication()
                ->bootComponent('com_content')
                ->getMVCFactory()
                ->createModel('Article', 'Administrator');

            $article = [];
            $isNewArticle = true;
            $state = 1;
            if ($articleData['articleid'] > 0) {
                //var_dump($articleData['articleid']);
                $article = $model->getItem((int)$articleData['articleid']);
                if (empty($article) || !$article->id) {
                    unset($article);
                    $state = 0;
                    $errors[$strNum + 1] = Text::sprintf('PLG_CFI_IMPORT_LOAD_ARTICLE', $articleData['articleid']);
                    // Save taskId data
                    file_put_contents(
                        $this->getTaskIdFile($task_id),
                        json_encode([
                            'total' => count($lines),
                            'current' => $strNum,
                            'type' => 'import',
                            'status' => 'inprogress',
                            'errors_count' => count($errors),
                            'errors' => $errors,
                            'inserts' => $inserts,
                            'updates' => $updates,
                            'continues' => $continues,
                        ])
                    );
                    continue;
                } else {
                    $isNewArticle = false;
                    $article = (array)$article;
                    unset($article[array_key_first($article)]);
                    if (isset($article['tags'])) {
                        $article['tags'] = explode(',', $article['tags']->tags);
                    }

                    // set new data on existing article item
                    $article['title'] = $articleData['articletitle'];
                    $article['introtext'] = $articleData['articleintrotext'];
                    $article['fulltext'] = $articleData['articlefulltext'];
                }
            }

            if ($isNewArticle) {
                //set data on new article item
                $article['id']         = 0;
                $article['title']      = $articleData['articletitle'];
                $article['alias']      = OutputFilter::stringURLSafe($article['title']);
                $article['introtext']  = $articleData['articleintrotext'];
                $article['fulltext']   = $articleData['articlefulltext'];
                $article['catid']      = $articleData['articlecat'];
                $article['language']   = $articleData['articlelang'];
                $article['featured']   = 0;
                $article['created']    = (new Date())->toSql();
                $article['created_by'] = explode(':', $this->user)[0];
                $article['state']      = $state;
                $article['access']     = $this->getApplication()->get('access', 1);
                $article['metadata']   = [
                    'robots'     => '',
                    'author'     => '',
                    'rights'     => '',
                    'xreference' => ''
                ];
                $article['images']     = [
                    'image_intro'            => '',
                    'float_intro'            => '',
                    'image_intro_alt'        => '',
                    'image_intro_caption'    => '',
                    'image_fulltext'         => '',
                    'float_fulltext'         => '',
                    'image_fulltext_alt'     => '',
                    'image_fulltext_caption' => ''
                ];
                $article['urls']       = [
                    'urla'     => false,
                    'urlatext' => '',
                    'targeta'  => '',
                    'urlb'     => false,
                    'urlbtext' => '',
                    'targetb'  => '',
                    'urlc'     => false,
                    'urlctext' => '',
                    'targetc'  => ''
                ];
                $article['attribs']    = [
                    'article_layout'           => '',
                    'show_title'               => '',
                    'link_titles'              => '',
                    'show_tags'                => '',
                    'show_intro'               => '',
                    'info_block_position'      => '',
                    'info_block_show_title'    => '',
                    'show_category'            => '',
                    'link_category'            => '',
                    'show_parent_category'     => '',
                    'link_parent_category'     => '',
                    'show_associations'        => '',
                    'show_author'              => '',
                    'link_author'              => '',
                    'show_create_date'         => '',
                    'show_modify_date'         => '',
                    'show_publish_date'        => '',
                    'show_item_navigation'     => '',
                    'show_icons'               => '',
                    'show_print_icon'          => '',
                    'show_email_icon'          => '',
                    'show_vote'                => '',
                    'show_hits'                => '',
                    'show_noauth'              => '',
                    'urls_position'            => '',
                    'alternative_readmore'     => '',
                    'article_page_title'       => '',
                    'show_publishing_option'   => '',
                    'show_article_options'     => '',
                    'show_urls_images_backend' => '',
                    'show_urls_images_fronten' => '',
                ];
            }

            // article form
            $form = $model->getForm($article, true);
            $errs = [];
            if (!$form) {
                foreach ($model->getErrors() as $error) {
                    $errs[] = ($error instanceof Exception) ? $error->getMessage() : $error;
                }
                if (!empty($errors[$strNum + 1])) {
                    $errors[$strNum + 1] .= '. ' . Text::_('PLG_CFI_IMPORT_SAVE_ARTICLE') . ': ' . implode('; ', $errs);
                } else {
                    $errors[$strNum + 1] = Text::_('PLG_CFI_IMPORT_SAVE_ARTICLE') . ': ' . implode('; ', $errs);
                }
                unset($model, $article, $errs);
                $continues++;
                // Save taskId data
                file_put_contents(
                    $this->getTaskIdFile($task_id),
                    json_encode([
                        'total' => count($lines),
                        'current' => $strNum,
                        'type' => 'import',
                        'status' => 'inprogress',
                        'errors_count' => count($errors),
                        'errors' => $errors,
                        'inserts' => $inserts,
                        'updates' => $updates,
                        'continues' => $continues,
                    ])
                );
                continue;
            }

            // save article item
            $this->getApplication()->getInput()->set('task', 'save');
            if ($model->save($article) === false) {
                foreach ($model->getErrors() as $error) {
                    $errs[] = ($error instanceof Exception) ? $error->getMessage() : $error;
                }
                if (!empty($errors[$strNum + 1])) {
                    $errors[$strNum + 1] .= '. ' . Text::_('PLG_CFI_IMPORT_SAVE_ARTICLE') . ': ' . implode('; ', $errs);
                } else {
                    $errors[$strNum + 1] = Text::_('PLG_CFI_IMPORT_SAVE_ARTICLE') . ': ' . implode('; ', $errs);
                }
                unset($model, $article, $errs);
                $continues++;
                // Save taskId data
                file_put_contents(
                    $this->getTaskIdFile($task_id),
                    json_encode([
                        'total' => count($lines),
                        'current' => $strNum,
                        'type' => 'import',
                        'status' => 'inprogress',
                        'errors_count' => count($errors),
                        'errors' => $errors,
                        'inserts' => $inserts,
                        'updates' => $updates,
                        'continues' => $continues,
                    ])
                );
                continue;
            } else {
                if (!empty($errors[$strNum + 1])) {
                    $errors[$strNum + 1] .= '. ' . Text::_('PLG_CFI_IMPORT_SAVENEW_ARTICLE');
                }
            }

            if ($isNewArticle) {
                $inserts++;

                // get ID for the new article
                $article['id'] = $model->getState($model->getName() . '.id');
            } else {
                $updates++;
            }

            // get article custom fields
            $jsFields = FieldsHelper::getFields('com_content.article', $article, true);
            foreach ($jsFields as $key => $jsField) {
                $jsFields[$jsField->name] = $jsField;
                unset($jsFields[$key]);
            }

            // save field's values
            $fieldsErrors = [];
            foreach ($fieldsData as $fieldName => $fieldValue) {
                if (array_key_exists($fieldName, $jsFields)) {
                    if (
                        $jsFields[$fieldName]->type === 'checkboxes' ||
                        in_array($jsFields[$fieldName]->type, array_keys($this->fieldPlugins))
                    ) {
                        $decode = json_decode($fieldValue, true);
                        $fieldValue = json_last_error() === JSON_ERROR_NONE ? $decode : [$fieldValue];
                    } elseif (strpos($fieldValue, 'array::') === 0) {
                        $fieldValue = json_decode(explode('::', $fieldValue, 2)[1]);
                    }
                    if (!$fieldModel->setFieldValue($jsFields[$fieldName]->id, $article['id'], $fieldValue)) {
                        $fieldsErrors[] = $fieldName;
                    }
                }
            }
            if ($fieldsErrors) {
                $errors[$strNum + 1] = Text::sprintf('PLG_CFI_IMPORT_SAVE_FIELDS', implode(', ', $fieldsErrors));
            }

            // Save taskId data
            file_put_contents(
                $this->getTaskIdFile($task_id),
                json_encode([
                    'total' => count($lines),
                    'current' => $strNum,
                    'type' => 'import',
                    'status' => 'inprogress',
                    'errors_count' => count($errors),
                    'errors' => $errors,
                    'inserts' => $inserts,
                    'updates' => $updates,
                    'continues' => $continues,
                ])
            );
            // destroy article instance
            unset($article, $jsFields);
        }

        // show result
        $log_data['result'] = Text::sprintf('PLG_CFI_RESULT', $inserts + $updates, $inserts, $updates) .
            ($errors ? '<br>' . Text::sprintf('PLG_CFI_RESULT_ERROR', $continues) : '');
        if ($errors) {
            $log_data['errors'] = $errors;
        } else {
            unlink($this->file);
        }
        $this->saveToLog($log_data, Log::INFO);
        echo new JsonResponse([],$log_data['result'], false);
//        $this->printJson($data['result'], true);
        return true;
    }

    private function getCategories()
    {
        $db = $this->getDatabase();
        $query = $db->createQuery()
            ->select('id')
            ->from('#__categories')
            ->where('extension = "com_content"')
            ->order('id');
        $db->setQuery($query);
        try {
            return $db->loadColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get absolute path to task file
     *
     * @param   string  $task_id
     *
     * @return string
     *
     * @since 2.0.0
     */
    private function getTaskIdFile(string $task_id): string
    {
        return Path::clean(sprintf($this->task_id_file, $task_id));
    }

    /**
     * Delete temporary task json file
     *
     * @param   string  $task_id
     *
     *
     * @since 2.0.0
     */
    private function deleteTaskFile(string $task_id)
    {
        $file = $this->getTaskIdFile($task_id);
        if (is_file($file)) {
            File::delete($file);
        }
    }

    /**
     * Check task status for ajax request
     * from admin panel
     *
     * @param   string  $task_id
     *
     * @return string[]
     *
     * @since 2.0.0
     */
    private function checkTaskStatus(string $task_id): array
    {
        $task_file = $this->getTaskIdFile($task_id);
        if (file_exists($task_file)) {
            $jsonContent = file_get_contents($task_file);
            if (!empty(trim($jsonContent))) {
                return (new Registry($jsonContent))->toArray();
            } else {
                return ['message' => 'no data for specified task'];
            }
        }

        return ['message' => 'specified task file is not found'];
    }

    private function exportArticles(string $task_id):void
    {
        $start = 0;
        while (true) {
            $model = $this->getApplication()
                ->bootComponent('com_content')
                ->getMVCFactory()
                ->createModel('Articles', 'Administrator');
            $total = $model->getTotal();
            $limit = $model->getState('list.limit');

            $model->setState('list.start', $start);
            $articles = $model->getItems();

            $count = count($articles);
            // Save taskId data
            file_put_contents(
                $this->getTaskIdFile($task_id),
                json_encode(['total' => $total, 'current' => $start, 'type' => 'export', 'status' => 'inprogress'])
            );

            $this->saveToCSV($articles);
            if ($count !== $limit) {
                break;
            }
            $start = $start + $limit;
            unset($model);
        }

        if ($this->getApplication()->getInput()->getBool('cficonvert', false)) {
            $this->convertFile();
        }

        // return result
        $download_url = new Uri(Uri::root());
        $download_url->setPath(str_replace(JPATH_SITE, '', $this->file));

        // Завершаем для фронта.
        file_put_contents(
            $this->getTaskIdFile($task_id),
            json_encode(
                [
                    'total'   => $total,
                    'current' => $total,
                    'type'    => 'export',
                    'status'  => 'completed',
                    'url'     => $download_url->toString(),
                ]
            )
        );
    }

    /**
     * Save data to CSV file
     *
     * @param $articles
     *
     * @return true
     *
     * @throws Exception
     * @since 2.0.0
     */
    private function saveToCSV($articles)
    {
        if (!$articles) {
            $this->saveToLog(Text::_('PLG_CFI_EXPORT_EMPTY_CONTENT'), Log::ERROR);
            throw new Exception(Text::_('PLG_CFI_EXPORT_EMPTY_CONTENT'), 500);
        }

        // append or write
        $file_mode = (file_exists($this->file) ? 'a' : 'w');
        // file handler
        if (($fileHandle = fopen($this->file, $file_mode)) === false) {
            $this->saveToLog(Text::_('PLG_CFI_EXPORTFILE_CREATE'), Log::ERROR);
            throw new Exception(Text::_('PLG_CFI_EXPORTFILE_CREATE'), 500);
        }

        $article_props = $this->params->get('article_fields', ['id', 'title', 'language', 'introtext', 'fulltext']);
        if ($this->params->get('use_tags', 0)) {
            $article_props[] = 'tags';
            $this->addTagsToArticles($articles);
        }
        $columns = [];
        if ($file_mode == 'w') {
            // make columns
            $columns = array_map(function ($article_prop) {
                return 'article' . $article_prop;
            }, $article_props);

            $jsFields = FieldsHelper::getFields('com_content.article', $articles[0], true);
            foreach ($jsFields as $jsField) {
                $columns[] = $jsField->name;
            }
        }

        $prepareEvent = AbstractEvent::create(
            'onExportPrepareData',
            [
                'subject'  => $this,
                'columns'  => $columns,
                'articles' => $articles,
                'fileMode' => $file_mode,
            ]
        );

        $dispatcher = new Dispatcher();
        PluginHelper::importPlugin('cfi', null, true, $dispatcher);
        $results  = $dispatcher->dispatch($prepareEvent->getName(), $prepareEvent);
        $columns  = $results['columns'];
        $articles = $results['articles'];

        // Write table headers
        if ($file_mode == 'w') {
            fputcsv($fileHandle, $columns, ';', '"', "\\", PHP_EOL);
        }
        // processing
        foreach ($articles as $article) {
            $outItem = [];
//            $outItem[] = $article->id;
//            $outItem[] = $article->catid;
//            $outItem[] = str_replace(["\n", "\r"], ' ', $article->title);
//            $outItem[] = str_replace(["\n", "\r"], ' ', $article->language);
//            $outItem[] = str_replace(["\n", "\r"], ' ', $article->introtext);
//            $outItem[] = str_replace(["\n", "\r"], ' ', $article->fulltext);
            foreach ($article_props as $property) {
//                $outItem[] = $article->id;
//                $outItem[] = $article->catid;
                $outItem[] = is_string($article->$property) ? str_replace(["\n", "\r"],
                    ' ',
                    $article->$property) : $article->$property;
//                $outItem[] = str_replace(["\n", "\r"], ' ', $article->language);
//                $outItem[] = str_replace(["\n", "\r"], ' ', $article->introtext);
//                $outItem[] = str_replace(["\n", "\r"], ' ', $article->fulltext);
            }


            if ($this->params->get('use_custom_fields', 0)) {
                $jsFields = FieldsHelper::getFields('com_content.article', $article, true);
                foreach ($jsFields as $jsField) {
                    if (
                        $jsField->type === 'checkboxes' ||
                        in_array($jsField->type, array_keys($this->fieldPlugins))
                    ) {
                        $outItem[] =
                            is_countable($jsField->rawvalue) && count($jsField->rawvalue) > 1
                                ? json_encode($jsField->rawvalue)
                                : (is_array($jsField->rawvalue) ? $jsField->rawvalue[0] : $jsField->rawvalue);
                    } elseif (is_array($jsField->rawvalue)) {
                        $outItem[] = 'array::' . json_encode($jsField->rawvalue);
                    } else {
                        $outItem[] = str_replace(["\n", "\r"], ' ', $jsField->rawvalue);
                    }
                }
            }

            fputcsv($fileHandle, $outItem, ';', '"', "\\", PHP_EOL);
        }

        // save file
        fclose($fileHandle);
        unset($articles, $jsFields);

        return true;
    }

    /**
     *
     * @param   array|string  $data      error message
     * @param   string        $priority  Joomla Log priority
     *
     * @return  void
     * @since   2.0.0
     */
    public function saveToLog(array|string $data, int $priority = Log::NOTICE): void
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }

        Log::addLogger(
            [
                // Sets file name
                'text_file' => 'cfi.php',
            ],
            // Sets all but DEBUG log level messages to be sent to the file
            Log::ALL & ~Log::DEBUG,
            ['plg_system_cfi']
        );

        Log::add($data, $priority, 'plg_system_cfi');
    }

    /**
     * Add tag ids for article item
     *
     * @param $articles
     *
     *
     * @since 2.0.0
     */
    private function addTagsToArticles(&$articles)
    {
        $tagsHelper = new TagsHelper();
        foreach ($articles as $item) {
            $item->tags = $tagsHelper->getTagIds($item->id, 'com_content.article');
        }
    }


    /**
     * Convert `$this->file` to specified codepage.
     * Wrapper for `mb_convert_encoding`
     *
     * @param   string|null  $from_encoding
     * @param   string|null  $to_encoding
     *
     * @return bool true on success
     * @throws \Exception
     * @since 2.0.0
     */
    private function convertFile(?string $from_encoding = null, ?string $to_encoding = null):bool
    {
        if(!$to_encoding) {
            $to_encoding = $this->cp;
        }
        if(!$from_encoding) {
            $from_encoding = 'UTF-8';
        }
        // convert
        $contentIn = file_get_contents($this->file);
        if ($contentIn !== false) {
            $content = mb_convert_encoding($contentIn, $to_encoding, $from_encoding);
            if (!$content) {
                $data = [
                    'result' => Text::_('PLG_CFI_EXPORT_ERROR_CONVERT'),
                    'file'   => $this->file,
                ];
                $this->saveToLog($data, Log::ERROR);
                throw new Exception(Text::_('PLG_CFI_EXPORT_ERROR_CONVERT'), 500);

            }
            if (file_put_contents($this->file, $content) === false) {
                $data = [
                    'result' => Text::_('PLG_CFI_EXPORT_ERROR_AFTER_CONVERT'),
                    'file'   => $this->file,
                ];
                $this->saveToLog($data, Log::ERROR);
                throw new Exception(Text::_('PLG_CFI_EXPORT_ERROR_AFTER_CONVERT'), 500);

            }
        } else {
            $data = [
                'result' => Text::_('PLG_CFI_EXPORT_ERROR_BEFORE_CONVERT'),
                'file'   => $this->file,
            ];

            $this->saveToLog($data, Log::ERROR);
            throw new Exception(Text::_('PLG_CFI_EXPORT_ERROR_BEFORE_CONVERT'), 500);

        }

        return true;
    }

    /**
     * Get categories titles for display in export params in admin panel
     *
     * @param   array  $cat_ids
     *
     * @return array
     *
     * @since 2.0.0
     */
    private function getCategoryTitles(array $cat_ids): array
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery();
        $query->select($db->quoteName('title'))
            ->from('#__categories')
            ->whereIn($db->quoteName('id'), $cat_ids);

        return $db->setQuery($query)->loadColumn();
    }

    /**
     * Get categories titles for display in export params in admin panel
     *
     * @param   array  $tag_ids
     *
     * @return array
     *
     * @since 2.0.0
     */
    private function getTagTitles(array $tag_ids): array
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery();
        $query->select($db->quoteName('title'))
            ->from('#__tags')
            ->whereIn($db->quoteName('id'), $tag_ids);

        return $db->setQuery($query)->loadColumn();
    }


    /**
     * Upload a CSV
     *
     * @return false|void
     *
     * @since 2.0.0
     */
    private function uploadCSV()
    {
        $input = $this->getApplication()->getInput();
        $userfile = $input->files->get('upload_file', null);

        // Make sure that file uploads are enabled in php.
        if (!(bool) ini_get('file_uploads'))
        {
            echo new JsonResponse('', Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_SERVER_DENY_FILE_UPLOAD'), true);

            return false;
        }

        // If there is no uploaded file, we have a problem...
        if (!\is_array($userfile))
        {
            echo new JsonResponse('', Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_NO_FILE_SELECTED'), true);

            return false;
        }

        // Is the PHP tmp directory missing?
        if ($userfile['error'] && ($userfile['error'] == UPLOAD_ERR_NO_TMP_DIR))
        {
            echo new JsonResponse('', Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_UPLOADERROR') . '<br>' . Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_PHPUPLOADNOTSET'), true);

            return false;
        }

        // Is the max upload size too small in php.ini?
        if ($userfile['error'] && ($userfile['error'] == UPLOAD_ERR_INI_SIZE))
        {

            echo new JsonResponse('', Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_UPLOADERROR') . '<br>' . Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_SMALLUPLOADSIZE'), true);

            return false;
        }

        // Check allowed file MIME-type, file extension, file size etc.
        if (!(new MediaHelper)->canUpload($userfile))
        {
            echo new JsonResponse('', Text::_('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_WRONG_MIME_TYPE'), true);

            return false;
        }

        // Сюда попадает всё неразобранное
        $upload_dir = $this->config->get('tmp_path', JPATH_SITE.'/tmp');
        $taskId = (new Date())->toUnix();
        $file_ext = File::getExt($userfile['name']);
        $tmp_dest = $upload_dir . '/' . $taskId.'.'.$file_ext;
        $tmp_src  = $userfile['tmp_name'];

        $result = false;
        if($tmp_src && $tmp_dest)
        {
            // Remove previous uploaded file
            if(is_file($tmp_dest)) {
                File::delete($tmp_dest);
            }
            $result = File::upload($tmp_src, $tmp_dest, false);
        }

        if ($result)
        {
            $csv = [];
            if (($handle = fopen($tmp_dest, "r")) !== false) {
                while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                    $csv[] = $data;
                }
                fclose($handle);
            }
            $upload_data = [
                'articles_count' => ((count($csv)) - 1), // w/o headers
                'filename'       => $userfile['name'],
                'taskId'       => $taskId,
            ];
            echo new JsonResponse($upload_data, Text::_('PLG_CFI_IMPORT_UPLOAD_FILE_OK'), false);
        } else {
            echo new JsonResponse($result, Text::_('PLG_CFI_IMPORT_UPLOAD_FAIL'), true);
        }
    }
}


