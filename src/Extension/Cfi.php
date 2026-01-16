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
use Generator;
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
use Joomla\CMS\User\CurrentUserTrait;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\Dispatcher;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Path;
use Joomla\Filter\OutputFilter;
use Joomla\Registry\Registry;

use RuntimeException;

use function defined;
use function fputcsv;
use function htmlspecialchars;
use function is_array;
use function is_file;

defined('_JEXEC') or die;


final class Cfi extends CMSPlugin implements SubscriberInterface
{

    use DatabaseAwareTrait;
    use CurrentUserTrait;

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
    private string $stop_file;
    /**
     * @var string
     * @since 2.0.0
     */
    private string $task_id_file;

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->config = Factory::getContainer()->get('config');
        $tmp_path = Path::clean($this->config->get('tmp_path'));
        if(!is_dir($tmp_path)) {
            $tmp_path = JPATH_SITE . DIRECTORY_SEPARATOR . 'tmp';
        }
        $this->file   = Path::clean($tmp_path . DIRECTORY_SEPARATOR . (new Date())->toUnix() . '.csv');
        $this->task_id_file = $this->config->get('tmp_path') . '/cfi_task_%s.json';
        $this->stop_file = $this->config->get('tmp_path') . '/cfi_task_%s_stop.txt';

        $this->fieldPlugins = [
            'imagelist'     => 0,
            'integer'       => 0,
            'list'          => 0,
            'sql'           => 0,
            'usergrouplist' => 0,
        ];


        $user       = $this->getCurrentUser();
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

    /**
     * @param   AjaxEvent  $event
     *
     *
     * @throws Exception
     * @since 1.0.0
     */
    public function onAjaxCfi(AjaxEvent $event)
    {

        if (!$this->getApplication()->isClient('administrator')) {
            return;
        }
        // We need both GET and POST tokens check for different actions
        (Session::checkToken('get') || Session::checkToken('post')) or die(Text::_('JINVALID_TOKEN'));

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
            case 'stop_task':
                $this->stopTask($task_id);
                break;
            case 'viewModal':
            default:
                $this->getViewModal();
            break;
        }
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
        File::write($task_file, json_encode($task_data));
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
     * Get absolute path to stop task file
     *
     * @param  string  $task_id
     *
     * @return string
     *
     * @since 2.0.0
     */
    private function getStopTaskFile(string $task_id): string
    {
        return Path::clean(sprintf($this->stop_file, $task_id));
    }


    /**
     * Stop task by task id
     *
     * @param   string  $task_id
     *
     *
     * @since 2.0.0
     */
    private function stopTask(string $task_id): void
    {
        File::write($this->getStopTaskFile($task_id), '');
    }

    /**
     * Check if task has been stopped by user from frontend
     *
     * @param   string  $task_id
     *
     *
     * @return bool
     * @since 2.0.0
     */
    private function checkStop(string $task_id): bool
    {
        $stopped = is_file($this->getStopTaskFile($task_id));
        if ($stopped) {
            $this->deleteTaskFile($task_id);
        }
        return $stopped;
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
        $files = [
            $this->getStopTaskFile($task_id),
            $this->getTaskIdFile($task_id),
            $this->file
        ];
        foreach ($files as $file) {
            if(is_file($file)){
                File::delete($file);
            }
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

    /**
     * Upload a CSV
     *
     * @return false|void
     *
     * @since 2.0.0
     */
    private function uploadCSV()
    {
        $input    = $this->getApplication()->getInput();
        $userfile = $input->files->get('upload_file', null);

        // Make sure that file uploads are enabled in php.
        if (!(bool)ini_get('file_uploads')) {
            echo new JsonResponse('', Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_SERVER_DENY_FILE_UPLOAD'), true);

            return false;
        }

        // If there is no uploaded file, we have a problem...
        if (!is_array($userfile)) {
            echo new JsonResponse('', Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_NO_FILE_SELECTED'), true);

            return false;
        }

        // Is the PHP tmp directory missing?
        if ($userfile['error'] && ($userfile['error'] == UPLOAD_ERR_NO_TMP_DIR)) {
            echo new JsonResponse(
                '',
                Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_UPLOADERROR') . ' ' . Text::_(
                    'PLG_CFI_IMPORT_UPLOAD_WARN_PHPUPLOADNOTSET'
                ),
                true
            );

            return false;
        }

        // Is the max upload size too small in php.ini?
        if ($userfile['error'] && ($userfile['error'] == UPLOAD_ERR_INI_SIZE)) {
            echo new JsonResponse(
                '',
                Text::_('PLG_CFI_IMPORT_UPLOAD_WARN_UPLOADERROR') . ' ' . Text::_(
                    'PLG_CFI_IMPORT_UPLOAD_WARN_SMALLUPLOADSIZE'
                ),
                true
            );

            return false;
        }

        // Check allowed file MIME-type, file extension, file size etc.
        if (!(new MediaHelper())->canUpload($userfile)) {
            echo new JsonResponse('', Text::_('PLG_CFI_IMPORT_UPLOAD_ERROR_UPLOAD_WRONG_MIME_TYPE'), true);

            return false;
        }

        // Сюда попадает всё неразобранное
        $upload_dir = $this->config->get('tmp_path', JPATH_SITE . '/tmp');
        $taskId     = (new Date())->toUnix();
        $file_ext   = File::getExt($userfile['name']);
        $tmp_dest   = $upload_dir . '/' . $taskId . '.' . $file_ext;
        $tmp_src    = $userfile['tmp_name'];

        $result = false;
        if ($tmp_src && $tmp_dest) {
            // Remove previous uploaded file
            if (is_file($tmp_dest)) {
                File::delete($tmp_dest);
            }
            $result = File::upload($tmp_src, $tmp_dest, false);
        }

        if ($result) {
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
                'taskId'         => $taskId,
            ];
            echo new JsonResponse($upload_data, Text::_('PLG_CFI_IMPORT_UPLOAD_FILE_OK'), false);
        } else {
            echo new JsonResponse($result, Text::_('PLG_CFI_IMPORT_UPLOAD_FAIL'), true);
        }
    }

    /**
     * @param   string  $task_id
     *
     *
     * @return bool
     * @throws Exception
     * @since 1.0.0
     */
    private function importArticles(string $task_id)
    {
        $this->file = Path::clean($this->config->get('tmp_path') . '/' . $task_id . '.csv');
        // log template
        $log_data = [
            'result' => '',
            'user'   => $this->user,
            'file'   => $this->file,
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
        $isConvert = (int)$this->getApplication()->getInput()->getBool('cficonvert', false);

        if ($isConvert) {
            $converted = $this->convertFile($this->cp, 'UTF-8');
        }

        /** @var array $columns current file columns */
        [$columns, $lines_count] = array_values($this->getCsvMetadata($this->file, ';'));

        if (!in_array('articleid', $columns)) {
            $log_data['result'] = Text::_('PLG_CFI_IMPORT_NO_COLUMN');
            $this->saveToLog($log_data, Log::ERROR);
            echo new JsonResponse([], Text::_('PLG_CFI_IMPORT_NO_COLUMN'), true);

            return false;
        }

        // data processing
        $errors    = [];
        $inserts   = 0;
        $updates   = 0;
        $continues = 0;

        set_time_limit(0);

        $dispatcher = new Dispatcher();
        PluginHelper::importPlugin('cfi', null, true, $dispatcher);

        // Save taskId data
        File::write(
            $this->getTaskIdFile($task_id),
            json_encode([
                'total'                 => $lines_count,
                'current'               => 0,
                'current_article_title' => '',
                'type'                  => 'import',
                'status'                => 'inprogress',
                'errors_count'          => 0,
                'errors'                => $errors,
                'inserts'               => $inserts,
                'updates'               => $updates,
                'continues'             => $continues,
            ], JSON_UNESCAPED_UNICODE)
        );

        foreach ($this->readCsvRows($this->file, ';') as  $strNum => $fieldsData) {
            if($strNum === 0) continue; // skip table headers
            if($this->checkStop($task_id)) {
                $this->saveToLog('Import has been stopped manually by user', Log::INFO);
                return false;
            }
            $prepareEvent = AbstractEvent::create(
                'onImportPrepareArticleData',
                [
                    'subject' => $this,
                    'columns' => $columns,
                    'strNum'  => $strNum,
                    'article' => $fieldsData,
                ]
            );
            $results     = $dispatcher->dispatch($prepareEvent->getName(), $prepareEvent);
            /** @var array $columns current file columns */
            $columns     = $results['columns'];
            $fieldsData  = $results['article'];

            // check count columns
            if (count($fieldsData) != count($columns)) {
                $errors[$strNum + 1] = Text::_('PLG_CFI_IMPORT_COLUMN_EXCEPT');
                $continues++;

                // Save taskId data
                File::write(
                    $this->getTaskIdFile($task_id),
                    json_encode([
                        'total'                 => $lines_count,
                        'current'               => $strNum,
                        'current_article_title' => '',
                        'type'                  => 'import',
                        'status'                => 'inprogress',
                        'errors_count'          => count($errors),
                        'errors'                => $errors,
                        'inserts'               => $inserts,
                        'updates'               => $updates,
                        'continues'             => $continues,
                    ],JSON_UNESCAPED_UNICODE)
                );

                continue;
            }

            [$articleData, $fieldsData] =  array_values($this->computeArticleData($columns, $fieldsData));


            // get article instance
            $model = $this->getApplication()
                ->bootComponent('com_content')
                ->getMVCFactory()
                ->createModel('Article', 'Administrator', ['ignore_request' => true]);
            Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/forms');

            $article      = [];
            $isNewArticle = true;

            if ($articleData['id'] > 0) {
                $article = $model->getItem((int)$articleData['id']);
                if (empty($article) || !$article->id) {
                    unset($article);
                    $errors[$strNum + 1] = Text::sprintf('PLG_CFI_IMPORT_LOAD_ARTICLE', $articleData['id']);
                    // Save taskId data
                    File::write(
                        $this->getTaskIdFile($task_id),
                        json_encode([
                            'total'                 => $lines_count,
                            'current'               => $strNum,
                            'current_article_title' => '',
                            'type'                  => 'import',
                            'status'                => 'inprogress',
                            'errors_count'          => count($errors),
                            'errors'                => $errors,
                            'inserts'               => $inserts,
                            'updates'               => $updates,
                            'continues'             => $continues,
                        ],JSON_UNESCAPED_UNICODE)
                    );
                    continue;
                } else {
                    $isNewArticle = false;
                    $article = (array)$article;
                    if (isset($article['tags'])) {
                        $article['tags'] = explode(',', $article['tags']->tags);
                    }
                }
            }

            $template = $this->getImportArticleTemplate();

            if ($isNewArticle) {
                $article = array_replace_recursive($template, $articleData);
            } else {
                $article = array_replace_recursive((array)$article, $articleData);
            }
            // Save original category id for existing articles and set default category for new articles
            if($isNewArticle && empty($article['catid'])) {
                $article['catid'] = $this->getCategories()[0];
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
                File::write(
                    $this->getTaskIdFile($task_id),
                    json_encode([
                        'total'                 => $lines_count,
                        'current'               => $strNum,
                        'current_article_title' => '',
                        'type'                  => 'import',
                        'status'                => 'inprogress',
                        'errors_count'          => count($errors),
                        'errors'                => $errors,
                        'inserts'               => $inserts,
                        'updates'               => $updates,
                        'continues'             => $continues,
                    ], JSON_UNESCAPED_UNICODE)
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
                File::write(
                    $this->getTaskIdFile($task_id),
                    json_encode([
                        'total'                 => $lines_count,
                        'current'               => $strNum,
                        'current_article_title' => '',
                        'type'                  => 'import',
                        'status'                => 'inprogress',
                        'errors_count'          => count($errors),
                        'errors'                => $errors,
                        'inserts'               => $inserts,
                        'updates'               => $updates,
                        'continues'             => $continues,
                    ], JSON_UNESCAPED_UNICODE)
                );
                continue;
            } elseif (!empty($errors[$strNum + 1])) {
                $errors[$strNum + 1] .= '. ' . Text::_('PLG_CFI_IMPORT_SAVENEW_ARTICLE');
            }

            if ($isNewArticle) {
                $inserts++;

                // get ID for the new article
                $article['id'] = $model->getState($model->getName() . '.id');
            } else {
                $updates++;
            }

            // update hits, modified, modified by etc
            $this->updateArticleUneditableData($article, $columns);

            // get article custom fields
            $jsFields = FieldsHelper::getFields('com_content.article', $article, true);
            foreach ($jsFields as $key => $jsField) {
                $jsFields[$jsField->name] = $jsField;
                unset($jsFields[$key]);
            }

            // save field's values
            $fieldsErrors = [];
            $fieldModel = $this->getApplication()
                ->bootComponent('com_fields')
                ->getMVCFactory()
                ->createModel('Field', 'Administrator', ['ignore_request' => true]);

            Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/forms');

            foreach ($fieldsData as $fieldName => $fieldValue) {

                if (array_key_exists($fieldName, $jsFields)) {

                    if (
                        $jsFields[$fieldName]->type === 'checkboxes' ||
                        in_array($jsFields[$fieldName]->type, array_keys($this->fieldPlugins))
                    ) {
                        $decode     = json_decode($fieldValue, true);
                        $fieldValue = json_last_error() === JSON_ERROR_NONE ? $decode : [$fieldValue];
                    } elseif (str_starts_with($fieldValue, 'array::')) {
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
            File::write(
                $this->getTaskIdFile($task_id),
                json_encode([
                    'total'                 => $lines_count,
                    'current'               => $strNum,
                    'current_article_title' => \htmlspecialchars($article['title']),
                    'type'                  => 'import',
                    'status'                => 'inprogress',
                    'errors_count'          => count($errors),
                    'errors'                => $errors,
                    'inserts'               => $inserts,
                    'updates'               => $updates,
                    'continues'             => $continues,
                ], JSON_UNESCAPED_UNICODE)
            );
            // destroy article instance
            unset($model, $article, $jsFields, $fieldModel);
        }

        File::write(
            $this->getTaskIdFile($task_id),
            json_encode([
                'total'                 => $lines_count,
                'current'               => $lines_count,
                'current_article_title' => '',
                'type'                  => 'import',
                'status'                => 'completed',
                'errors_count'          => count($errors),
                'errors'                => $errors,
                'inserts'               => $inserts,
                'updates'               => $updates,
                'continues'             => $continues,
            ], JSON_UNESCAPED_UNICODE)
        );

        // show result
        $log_data['result'] = Text::sprintf('PLG_CFI_RESULT', $inserts + $updates, $inserts, $updates) .
            ($errors ? ' ' . Text::sprintf('PLG_CFI_RESULT_ERROR', $continues) : '');
        if ($errors) {
            $log_data['errors'] = $errors;
        } else {
            unlink($this->file);
        }
        $this->saveToLog($log_data, Log::INFO);

        return true;
    }

    /**
     * Return an array of categories ids
     *
     * @return array|bool
     *
     * @since 1.0.0
     */
    private function getCategories():array|bool
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('extension').' = '.$db->quote('com_content'))
            ->order('id');
        $db->setQuery($query);
        try {
            $result = (array)$db->loadColumn();
            unset($db, $query);
            return $result;
        } catch (Exception $e) {
            $this->saveToLog($e->getMessage().' File:'.$e->getFile().' Line:'.$e->getLine(),'error');
            unset($db, $query);
            return false;
        }
    }

    /**
     *
     * @param   array|object|string  $data      error message
     * @param   int           $priority  Joomla Log priority
     *
     * @return  void
     * @since   2.0.0
     */
    public function saveToLog(array|object|string $data, int $priority = Log::NOTICE): void
    {
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        Log::addLogger(
            [
                'text_file' => 'cfi.php',
            ],
            Log::ALL & ~Log::DEBUG,
            ['plg_system_cfi']
        );

        Log::add($data, $priority, 'plg_system_cfi');
    }

    /**
     * Convert `$this->file` to specified codepage.
     * Wrapper for `mb_convert_encoding`
     *
     * @param   string|null  $from_encoding
     * @param   string|null  $to_encoding
     *
     * @return bool true on success
     * @throws Exception
     * @since 2.0.0
     */
    private function convertFile(?string $from_encoding = null, ?string $to_encoding = null): bool
    {
        if (!$to_encoding) {
            $to_encoding = $this->cp;
        }
        if (!$from_encoding) {
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
            if (File::write($this->file, $content) === false) {
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
     * update hits, modified, modified by etc
     *
     * @param   array  $articleData
     * @param   array  $columns CSV columns
     *
     *
     * @return bool
     * @since 2.0.0
     */
    private function updateArticleUneditableData(array $articleData, array $columns): bool
    {

        if(!isset($articleData['id'])) {
            return false;
        }

        $normalized_columns = array_map(function($col) {
            return str_starts_with($col, 'article')
                ? substr($col, strlen('article'))
                : $col;
        }, $columns);

        $db    = $this->getDatabase();
        $query = $db->createQuery();

        $now = (new Date())->toSql();
        $user_id = $this->getCurrentUser()->id;

        $setParts = [];

        if (in_array('hits', $normalized_columns, true) && isset($articleData['hits'])) {
                $hits       = (int)$articleData['hits'];
                $setParts[] = $db->quoteName('hits') . ' = :hits';
                $query->bind(':hits', $hits, ParameterType::INTEGER);
        }

        // modified
        if (in_array('modified', $normalized_columns, true)) {
            if (isset($articleData['modified'])) {
                $modified = (string) $articleData['modified'];
            } else {
                $modified = $now;
            }
        } else {
            $modified = $now;
        }

        $setParts[] = $db->quoteName('modified') . ' = :modified';
        $query->bind(':modified', $modified, ParameterType::STRING);

        // modified_by
        if (in_array('modified_by', $normalized_columns, true)) {
            if (isset($articleData['modified_by'])) {
                $modified_by = (int) $articleData['modified_by'];
            } else {
                $modified_by = $user_id;
            }
        } else {
            $modified_by = $user_id;
        }

        $setParts[] = $db->quoteName('modified_by') . ' = :modified_by';
        $query->bind(':modified_by', $modified_by, ParameterType::INTEGER);

        if (empty($setParts)) {
            unset($db, $query);
            return false;
        }
        $article_id = (int)$articleData['id'];
        $query->update($db->quoteName('#__content'))
            ->set($setParts)
            ->where($db->quoteName('id') . ' = :article_id');

        $query->bind(':article_id', $article_id, ParameterType::INTEGER);

        try {
            $result = $db->setQuery($query)->execute();

        } catch (Exception $e) {
            $this->saveToLog($e->getMessage(), Log::ERROR);
            unset($db, $query);
            return false;
        }
        unset($db, $query);
        return $result;
    }

    /**
     * Prepare articles data for saving to CSV.
     * Then transfer data to saveToCSV() method
     *
     * @param   string  $task_id
     *
     *
     * @throws Exception
     * @since 2.0.0
     */
    private function exportArticles(string $task_id): void
    {
        // article properties like id, title, alias etc
        $article_props = $this->getApplication()->getInput()->get('article_props', []);
        // add id if it isn't exists
        if(!in_array('id', $article_props, true)) array_unshift($article_props,'id');
        // article custom fields names
        $article_fields = $this->getApplication()->getInput()->get('article_fields', []);

        $use_tags = $this->getApplication()->getInput()->getBool('use_tags', false);
        $use_custom_fields = $this->getApplication()->getInput()->getBool('use_custom_fields',false);

        $start = 0;
        while (true) {
            if($this->checkStop($task_id)) {
                $this->saveToLog('Export has been stopped manually by user', Log::INFO);
                return;
            }
            $model = $this->getApplication()
                ->bootComponent('com_content')
                ->getMVCFactory()
                ->createModel('Articles', 'Administrator');
            $total = $model->getTotal();
            $limit = $model->getState('list.limit');

            $model->setState('list.start', $start);
            $articles = $model->getItems();
            $page_current = $model->getPagination()->pagesCurrent;
            $page_total = $model->getPagination()->pagesTotal;
            // Save taskId data
            File::write(
                $this->getTaskIdFile($task_id),
                json_encode(['total' => $total, 'current' => $start, 'type' => 'export', 'status' => 'inprogress'])
            );

            $this->saveToCSV($articles, $task_id, $article_props, $article_fields, $use_tags, $use_custom_fields);

            if ($page_current == $page_total) {
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

        // Complete task for frontend
        File::write(
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
     * @param array   $articles        Articles data from database
     * @param string  $task_id
     * @param array   $article_props   articles properties like id, title, alias etc
     * @param array   $article_fields  articles custom fields names
     * @param bool    $use_tags        include article tags
     * @param bool    $use_custom_fields  include article custom fields
     *
     *
     * @throws Exception
     * @return bool
     *
     * @since 2.0.0
     */
    private function saveToCSV(array $articles, string $task_id, array $article_props, array $article_fields, bool $use_tags, bool $use_custom_fields):bool
    {
        if($this->checkStop($task_id)) {
            $this->saveToLog('Import has been stopped manually by user', Log::INFO);
            return false;
        }

        if (!$articles) {
            $this->saveToLog(Text::_('PLG_CFI_EXPORT_EMPTY_CONTENT'), Log::ERROR);
            throw new Exception(Text::_('PLG_CFI_EXPORT_EMPTY_CONTENT'), 500);
        }

        // append or write
        $file_mode = (file_exists($this->file) ? 'a' : 'w');
        $this->saveToLog(Text::sprintf('PLG_CFI_EXPORT_FILE_INFO', $this->file, $file_mode), Log::INFO);
        // file handler
        if (($fileHandle = fopen($this->file, $file_mode)) === false) {
            $this->saveToLog(Text::_('PLG_CFI_EXPORTFILE_CREATE'), Log::ERROR);
            throw new Exception(Text::_('PLG_CFI_EXPORTFILE_CREATE'), 500);
        }

        if(empty($article_props)){
            $article_props = $this->params->get('article_fields', ['id', 'title', 'catid', 'introtext', 'fulltext']);
        }

        if ($use_tags) {
            $article_props[] = 'tags';
            $this->addTagsToArticles($articles);
        }

        $columns = [];
        if ($file_mode == 'w') {
            // make columns
            $columns = array_map(function ($article_prop) {
                return 'article' . $article_prop;
            }, $article_props);

            if($use_custom_fields) {
                $jsFields = FieldsHelper::getFields('com_content.article', $articles[0], true);
                foreach ($jsFields as $jsField) {
                    if(in_array($jsField->name,$article_fields)) {
                        $columns[] = $jsField->name;
                    }
                }
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
            foreach ($article_props as $property) {
                $property_data = $article->$property;
                if(is_array($property_data)) {
                    $outItem[] =  'array::' . json_encode($property_data);
                } elseif(is_string($property_data)) {
                    $outItem[] =  str_replace(["\n", "\r"],' ', $property_data);
                } else {
                    $outItem[] =  $property_data;
                }
            }

            if($use_custom_fields) {
                $jsFields = FieldsHelper::getFields('com_content.article', $article, true);
                foreach ($jsFields as $jsField) {
                    if(!in_array($jsField->name,$article_fields)) continue;
                    if (
                        $jsField->type === 'checkboxes' ||
                        in_array($jsField->type, array_keys($this->fieldPlugins))
                    ) {
                        $outItem[] = is_countable($jsField->rawvalue) && count($jsField->rawvalue) > 1
                                ? json_encode($jsField->rawvalue)
                                : (is_array($jsField->rawvalue) ? $jsField->rawvalue[0] : $jsField->rawvalue);
                    } elseif (is_array($jsField->rawvalue)) {
                        $outItem[] = 'array::' . json_encode($jsField->rawvalue);
                    } else {
                        $outItem[] = str_replace(["\n", "\r"], ' ', $jsField->rawvalue);
                    }
                }
            }

            \fputcsv($fileHandle, $outItem, ';', '"', "\\", PHP_EOL);
        }

        // save file
        fclose($fileHandle);
        unset($articles, $jsFields);

        return true;
    }

    /**
     * Add tag ids for article item
     *
     * @param $articles
     *
     * @return void
     * @since 2.0.0
     */
    private function addTagsToArticles(&$articles):void
    {
        $tagsHelper = new TagsHelper();
        foreach ($articles as $item) {
            $item->tags = $tagsHelper->getTagIds($item->id, 'com_content.article');
        }
    }

    /**
     * Get categories titles for display in export params in admin panel
     *
     * @param  array  $cat_ids
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
     * Return article data structure
     *
     * @return array
     *
     * @since 2.0.0
     */
    private function getImportArticleTemplate(): array
    {
        $user_id = $this->getCurrentUser()->id;
        $current_date = (new Date())->toSql();

        return [
            'id'               => 0,
            'title'            => '',
            'alias'            => '',
            'introtext'        => '',
            'fulltext'         => '',
            'catid'            => '',
            'language'         => '*',
            'featured'         => 0,
            'created'          => $current_date,
            'created_by'       => $user_id,
            'state'            => 0,
            'access'           => $this->getApplication()->get('access', 1),
            'note'             => '',
            'modified'         => $current_date,
            'modified_by'      => $user_id,
            'hits'             => 0,
            'created_by_alias' => '',
            'publish_up'       => '',
            'publish_down'     => '',
            'featured_up'      => '',
            'featured_down'    => '',
            'metadata'         => [
                'metadesc'   => '',
                'metakey'    => '',
                'robots'     => '',
                'author'     => '',
                'rights'     => '',
                'xreference' => '',
            ],
            'images'           => [
                'image_intro'            => '',
                'float_intro'            => '',
                'image_intro_alt'        => '',
                'image_intro_caption'    => '',
                'image_fulltext'         => '',
                'float_fulltext'         => '',
                'image_fulltext_alt'     => '',
                'image_fulltext_caption' => '',
            ],
            'urls'             => [
                'urla'     => false,
                'urlatext' => '',
                'targeta'  => '',
                'urlb'     => false,
                'urlbtext' => '',
                'targetb'  => '',
                'urlc'     => false,
                'urlctext' => '',
                'targetc'  => '',
            ],
            'attribs'          => [
                'article_layout'            => '',
                'show_title'                => '',
                'link_titles'               => '',
                'show_tags'                 => '',
                'show_intro'                => '',
                'info_block_position'       => '',
                'info_block_show_title'     => '',
                'show_category'             => '',
                'link_category'             => '',
                'show_parent_category'      => '',
                'link_parent_category'      => '',
                'show_associations'         => '',
                'show_author'               => '',
                'link_author'               => '',
                'show_create_date'          => '',
                'show_modify_date'          => '',
                'show_publish_date'         => '',
                'show_item_navigation'      => '',
                'show_icons'                => '',
                'show_print_icon'           => '',
                'show_email_icon'           => '',
                'show_vote'                 => '',
                'show_hits'                 => '',
                'show_noauth'               => '',
                'urls_position'             => '',
                'alternative_readmore'      => '',
                'article_page_title'        => '',
                'show_publishing_option'    => '',
                'show_article_options'      => '',
                'show_urls_images_backend'  => '',
                'show_urls_images_frontend' => '',
            ],
        ];
    }

    /**
     * Detect which parameter group the data column belongs to.
     *
     * @param  string  $column_name
     *
     * @return string|false
     *
     * @since 2.0.0
     */
    private function getImportColumnNameGroup(string $column_name): string|false
    {
        $article  = [
            'id',
            'title',
            'alias',
            'introtext',
            'fulltext',
            'catid',
            'language',
            'featured',
            'created',
            'created_by',
            'state',
            'access',
            'note',
            'modified',
            'modified_by',
            'hits',
            'created_by_alias',
            'publish_up',
            'publish_down',
            'featured_up',
            'featured_down',
        ];

        $metadata = [
            'metadesc',
            'metakey',
            'robots',
            'author',
            'rights',
            'xreference',
        ];

        $images = [
            'image_intro',
            'float_intro',
            'image_intro_alt',
            'image_intro_caption',
            'image_fulltext',
            'float_fulltext',
            'image_fulltext_alt',
            'image_fulltext_caption',
        ];

        $urls = [
            'urla',
            'urlatext',
            'targeta',
            'urlb',
            'urlbtext',
            'targetb',
            'urlc',
            'urlctext',
            'targetc',
        ];

        $attribs = [
            'article_layout',
            'show_title',
            'link_titles',
            'show_tags',
            'show_intro',
            'info_block_position',
            'info_block_show_title',
            'show_category',
            'link_category',
            'show_parent_category',
            'link_parent_category',
            'show_associations',
            'show_author',
            'link_author',
            'show_create_date',
            'show_modify_date',
            'show_publish_date',
            'show_item_navigation',
            'show_icons',
            'show_print_icon',
            'show_email_icon',
            'show_vote',
            'show_hits',
            'show_noauth',
            'urls_position',
            'alternative_readmore',
            'article_page_title',
            'show_publishing_option',
            'show_article_options',
            'show_urls_images_backend',
            'show_urls_images_frontend',
        ];

        if (in_array($column_name, $article, true)) {
            return 'article';
        }
        if (in_array($column_name, $metadata, true)) {
            return 'metadata';
        }
        if (in_array($column_name, $images, true)) {
            return 'images';
        }
        if (in_array($column_name, $urls, true)) {
            return 'urls';
        }
        if (in_array($column_name, $attribs, true)) {
            return 'attribs';
        }

        return false;
    }

    /**
     *
     * Make article data array
     *
     * @param   array  $columns
     * @param   array  $fieldsData
     *
     *
     * @return array
     * @since 2.0.0
     */
    private function computeArticleData(array $columns, array $fieldsData):array
    {
        $articleData = [];
        foreach ($columns as $key => $column) {
            // column name like `articlehits`, `articleimages_image_intro`
            // remove `article` prefix
            // Check that it is in the beginning
            if (str_starts_with($column, 'article')) {
                $column = substr($column, strlen('article'));
            }

            if($data_group = $this->getImportColumnNameGroup($column)) {
                if($data_group == 'article') {
                    if($column == 'alias') {
                        $articleData[$column] = OutputFilter::stringURLSafe($fieldsData[$key]);
                    } elseif(in_array($column, ['created','modified','checked_out_time','publish_up','publish_down','featured_up','featured_down'])) {
                        // Fix date format to SQL
                        $articleData[$column] = (new Date($fieldsData[$key]))->toSql();
                    } elseif (str_starts_with($fieldsData[$key], 'array::')) {
                        $articleData[$column] =  json_decode(explode('::', $fieldsData[$key], 2)[1]);
                    } else {
                        $articleData[$column] = $fieldsData[$key];
                    }
                } elseif (str_starts_with($fieldsData[$key], 'array::')) {
                        $articleData[$data_group][$column] =  json_decode(explode('::', $fieldsData[$key], 2)[1]);
                } else {
                    $articleData[$data_group][$column] = $fieldsData[$key];
                }
                unset($fieldsData[$key]);
            } else {
                $fieldsData[$column] = $fieldsData[$key];
                unset($fieldsData[$key]);
            }
        }

        return ['articleData' => $articleData, 'fieldsData' => $fieldsData];
    }

    /**
     * @param   string  $filename Absolute path to CSV file
     * @param   string  $delimiter CSV columns delimiter
     *
     * @return Generator
     *
     * @since 2.0.0
     */
    private function readCsvRows(string $filename, string $delimiter = ','): Generator
    {
        $handle = fopen($filename, 'r');
        if (!$handle) {
            throw new RuntimeException("Не удалось открыть файл: $filename");
        }

        // Check and skip BOM (UTF-8)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (empty(array_filter(array_map('trim', $row)))) {
                continue;
            }
            yield $row;
        }

        fclose($handle);
    }

    /**
     * Reads the headers of the CSV file and counts the number of data lines (without the header line).
     *
     * @param   string  $filename   Absoulite path to the CSV file.
     * @param   string  $delimiter  The column separator character (default is `;`).
     *
     * @throws RuntimeException If it was not possible to open the file or read the headers.
     * @return array An array with the keys 'headers' (array of headers) and 'data_rows_count' (number of rows of data).
     * @since 2.0.0
     */
    private function getCsvMetadata(string $filename, string $delimiter = ';'): array
    {
        $handle = fopen($filename, 'r');
        if (!$handle) {
            throw new RuntimeException(Text::sprintf('PLG_CFI_IMPORT_GETCSVMETADATA_ERROR_OPEN_FILE', $filename), 404);
        }

        // Check and skip BOM (UTF-8)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = fgetcsv($handle, 0, $delimiter, ' ', "\\");

        if ($headers === false) {
            fclose($handle);
            throw new RuntimeException(Text::sprintf('PLG_CFI_IMPORT_GETCSVMETADATA_ERROR_READ_CSV_HEADERS', $filename), 500);
        }

        $total = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, ' ', "\\")) !== false) {
            if (!empty(array_filter(array_map('trim', $row)))) {
                $total++;
            }
        }

        fclose($handle);
        $columns = array_map('trim', $headers);

        return [
            'columns' => $columns,
            'total' => $total
        ];
    }

    /**
     * Get a modal iframe view
     *
     * @since 2.0.0
     */
    private function getViewModal()
    {
        $model = $this->getApplication()
            ->bootComponent('com_content')
            ->getMVCFactory()->createModel('Articles');
        $categories  = $fields_categories = $model->getState('filter.category_id', []);

        $tag_ids       = $model->getState('filter.tag');
        $article_props = $this->params->get(
            path: 'article_fields',
            default: ['id', 'title', 'language', 'introtext', 'fulltext']
        );

        $fake_item = new \stdClass();
        $fake_item->fieldscatid = $fields_categories;

        $jcfields = [];
        if (!empty($fields = FieldsHelper::getFields('com_content.article', $fake_item, false, null, false))) {
            foreach ($fields as $field) {
                $jcfields[$field->name] =  $field->title;
            }
        }

        $article_fields = array_keys($this->getImportArticleTemplate());
        $article_fields = array_combine($article_fields, $article_fields);
        $export_data   = [
            'total_items'      => $model->getTotal(),
            'categories_count' => (!empty($categories) ? count($categories) : Text::_('JALL')),
            'jcfields' => $jcfields,
            'article_fields' => $article_fields,
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
    }
}


