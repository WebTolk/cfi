<?php
/**
 * @package    System - CFI
 * @version       2.0.0
 * @Author        Sergey Tolkachyov, https://web-tolk.ru
 * @copyright     Copyright (C) 2024 Sergey Tolkachyov
 * @license       GNU/GPL http://www.gnu.org/licenses/gpl-3.0.html
 * @since         1.0.0
 */

// No direct access to this file
\defined('_JEXEC') or die();

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseDriver;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\Path;

return new class() implements ServiceProviderInterface {

    public function register(Container $container): void
    {
        $container->set(InstallerScriptInterface::class, new class($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {
            /**
             * The application object
             *
             * @var  AdministratorApplication
             *
             * @since  1.0.0
             */
            protected AdministratorApplication $app;

            /**
             * The Database object.
             *
             * @var   DatabaseDriver
             *
             * @since  1.0.0
             */
            protected DatabaseDriver $db;

            /**
             * Minimum Joomla version required to install the extension.
             *
             * @var  string
             *
             * @since  1.0.0
             */
            protected string $minimumJoomla = '4.3';

            /**
             * Minimum PHP version required to install the extension.
             *
             * @var  string
             *
             * @since  1.0.0
             */
            protected string $minimumPhp = '8.0';

            /**
             * @var array $providersInstallationMessageQueue
             * @since 2.0.3
             */
            protected $providersInstallationMessageQueue = [];

            /**
             * Constructor.
             *
             * @param   AdministratorApplication  $app  The application object.
             *
             * @since 1.0.0
             */
            public function __construct(AdministratorApplication $app)
            {
                $this->app = $app;
                $this->db  = Factory::getContainer()->get('DatabaseDriver');
            }

            /**
             * Function called after the extension is installed.
             *
             * @param   InstallerAdapter  $adapter  The adapter calling this method
             *
             * @return  boolean  True on success
             *
             * @since   1.0.0
             */
            public function install(InstallerAdapter $adapter): bool
            {
                $this->enablePlugin($adapter);

                return true;
            }

            /**
             * Function called after the extension is updated.
             *
             * @param   InstallerAdapter  $adapter  The adapter calling this method
             *
             * @return  boolean  True on success
             *
             * @since   1.0.0
             */
            public function update(InstallerAdapter $adapter): bool
            {
                return true;
            }

            /**
             * Function called after the extension is uninstalled.
             *
             * @param   InstallerAdapter  $adapter  The adapter calling this method
             *
             * @return  boolean  True on success
             *
             * @since   1.0.0
             */
            public function uninstall(InstallerAdapter $adapter): bool
            {
                // Remove layouts
                $this->removeLayouts($adapter->getParent()->getManifest()->layouts);
                return true;
            }

            /**
             * Function called before extension installation/update/removal procedure commences.
             *
             * @param   string            $type     The type of change (install or discover_install, update, uninstall)
             * @param   InstallerAdapter  $adapter  The adapter calling this method
             *
             * @return  boolean  True on success
             *
             * @since   1.0.0
             */
            public function preflight(string $type, InstallerAdapter $adapter): bool
            {
                // Check compatible
                if (!$this->checkCompatible($adapter->getElement())) {
                    return false;
                }

                return true;
            }

            /**
             * Function called after extension installation/update/removal procedure commences.
             *
             * @param   string            $type     The type of change (install or discover_install, update, uninstall)
             * @param   InstallerAdapter  $adapter  The adapter calling this method
             *
             * @return  boolean  True on success
             *
             * @since   1.0.0
             */
            public function postflight(string $type, InstallerAdapter $adapter): bool
            {
                if ($type != 'uninstall') {
                    // Parse layouts
                    $this->parseLayouts($adapter->getParent()->getManifest()->layouts, $adapter->getParent());
                }
                // Check key params

                $smile = '';
                if ($type != 'uninstall') {
                    $smiles    = ['&#9786;', '&#128512;', '&#128521;', '&#128525;', '&#128526;', '&#128522;', '&#128591;'];
                    $smile_key = array_rand($smiles, 1);
                    $smile     = $smiles[$smile_key];
                }

                $element = 'PLG_' . strtoupper($adapter->getElement());
                $type    = strtoupper($type);
                $html    = '
				<div class="row m-0">
				<div class="col-12 col-md-8 p-0 pe-2">
				<h2>' . $smile . ' ' . Text::_($element . '_AFTER_' . $type) . ' <br/>' . Text::_($element) . '</h2>
				' . Text::_($element . '_DESC');

                $html .= Text::_($element . '_WHATS_NEW');

                $html .= '</div>
				<div class="col-12 col-md-4 p-0 d-flex flex-column justify-content-start">
				<img width="180" src="https://web-tolk.ru/web_tolk_logo_wide.png">
				<p>Joomla Extensions</p>
				<p class="btn-group">
					<a class="btn btn-sm btn-outline-primary" href="https://web-tolk.ru" target="_blank"> https://web-tolk.ru</a>
					<a class="btn btn-sm btn-outline-primary" href="mailto:info@web-tolk.ru"><i class="icon-envelope"></i> info@web-tolk.ru</a>
				</p>
				<div class="btn-group-vertical mb-3 web-tolk-btn-links" role="group" aria-label="Joomla community links">
					<a class="btn btn-danger text-white w-100" href="https://t.me/joomlaru" target="_blank">' . Text::_($element . '_JOOMLARU_TELEGRAM_CHAT') . '</a>
					<a class="btn btn-primary text-white w-100" href="https://t.me/webtolkru" target="_blank">' . Text::_($element . '_WEBTOLK_TELEGRAM_CHANNEL') . '</a>
				</div>
				' . Text::_($element . "_MAYBE_INTERESTING") . '
				</div>

				';
                $this->app->enqueueMessage($html, 'info');

                return true;
            }

            /**
             * Enable plugin after installation.
             *
             * @param   InstallerAdapter  $adapter  Parent object calling object.
             *
             * @since  1.0.0
             */
            protected function enablePlugin(InstallerAdapter $adapter)
            {
                // Prepare plugin object
                $plugin          = new \stdClass();
                $plugin->type    = 'plugin';
                $plugin->element = $adapter->getElement();
                $plugin->folder  = (string) $adapter->getParent()->manifest->attributes()['group'];
                $plugin->enabled = 1;

                // Update record
                $this->db->updateObject('#__extensions', $plugin, ['type', 'element', 'folder']);
            }

            /**
             * Method to check compatible.
             *
             * @return  boolean True on success, False on failure.
             *
             * @throws  Exception
             *
             * @since  1.0.0
             */
            protected function checkCompatible(string $element): bool
            {
                $element = 'PLG_' . strtoupper($element);
                // Check joomla version
                if (!(new Version)->isCompatible($this->minimumJoomla)) {
                    $this->app->enqueueMessage(
                        Text::sprintf($element . '_ERROR_COMPATIBLE_JOOMLA', $this->minimumJoomla),
                        'error'
                    );

                    return false;
                }

                // Check PHP
                if (!(version_compare(PHP_VERSION, $this->minimumPhp) >= 0)) {
                    $this->app->enqueueMessage(
                        Text::sprintf($element . '_ERROR_COMPATIBLE_PHP', $this->minimumPhp),
                        'error'
                    );

                    return false;
                }

                return true;
            }

            /**
             * Method to parse through a layout element of the installation manifest and take appropriate action.
             *
             * @param   SimpleXMLElement  $element    The XML node to process.
             * @param   InstallerAdapter  $installer  Installer calling object.
             *
             * @return  boolean  True on success.
             *
             * @since  1.3.0
             */
            public function parseLayouts(SimpleXMLElement $element, $installer)
            {
                if (!$element || !count($element->children())) {
                    return false;
                }

                // Get destination
                $folder      = ((string) $element->attributes()->destination) ? '/' . $element->attributes()->destination : null;
                $destination = Path::clean(JPATH_ROOT . '/layouts' . $folder);

                // Get source
                $folder = (string) $element->attributes()->folder;
                $source = ($folder && file_exists($installer->getPath('source') . '/' . $folder)) ?
                    $installer->getPath('source') . '/' . $folder : $installer->getPath('source');

                // Prepare files
                $copyFiles = array();
                foreach ($element->children() as $file) {
                    $path['src']  = Path::clean($source . '/' . $file);
                    $path['dest'] = Path::clean($destination . '/' . $file);

                    // Is this path a file or folder?
                    $path['type'] = $file->getName() === 'folder' ? 'folder' : 'file';
                    if (basename($path['dest']) !== $path['dest']) {
                        $newdir = dirname($path['dest']);
                        if (!Folder::create($newdir)) {
                            Log::add(Text::sprintf('JLIB_INSTALLER_ERROR_CREATE_DIRECTORY', $newdir), Log::WARNING, 'jerror');

                            return false;
                        }
                    }

                    $copyFiles[] = $path;
                }

                return $installer->copyFiles($copyFiles);
            }
            /**
             * Method to parse through a layouts element of the installation manifest and remove the files that were installed.
             *
             * @param   SimpleXMLElement  $element  The XML node to process.
             *
             * @return  boolean  True on success.
             *
             * @since  1.3.0
             */
            protected function removeLayouts(SimpleXMLElement $element)
            {
                if (!$element || !count($element->children())) {
                    return false;
                }

                // Get the array of file nodes to process
                $files = $element->children();

                // Get source
                $folder = ((string) $element->attributes()->destination) ? '/' . $element->attributes()->destination : null;
                $source = Path::clean(JPATH_ROOT . '/layouts' . $folder);

                // Process each file in the $files array (children of $tagName).
                foreach ($files as $file) {
                    $path = Path::clean($source . '/' . $file);

                    // Actually delete the files/folders
                    if (is_dir($path)) {
                        $val = Folder::delete($path);
                    } else {
                        $val = File::delete($path);
                    }

                    if ($val === false) {
                        Log::add('Failed to delete ' . $path, Log::WARNING, 'jerror');

                        return false;
                    }
                }

                if (!empty($folder)) {
                    Folder::delete($source);
                }

                return true;
            }
        });
    }
};