<?php
namespace modmore\GoogleDriveMediaSource\Model;

use Google\Auth\OAuth2;
use Google\Client;
use Google\Service\Drive;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;
use modmore\GoogleDriveMediaSource\Adapter\Directory;
use modmore\GoogleDriveMediaSource\Adapter\DriveAdapter;
use modmore\GoogleDriveMediaSource\Adapter\File;
use modmore\RevolutionCache\Pool;
use MODX\Revolution\modX;
use MODX\Revolution\Sources\modMediaSource;
use Psr\Cache\InvalidArgumentException;
use xPDO\xPDO;

global $logger;
global $modx;

$logger = function($msg) use ($modx) {
    $modx->log(1, $msg);
};

/**
 * Class GoogleDriveMediaSource
 *
 *
 * @package modmore\GoogleDriveMediaSource\Model
 * @property DriveAdapter $adapter
 */
class GoogleDriveMediaSource extends modMediaSource
{
    /**
     * @var array|mixed|\xPDO\Om\xPDOObject|null
     */
    private static array $cacheOptions = [
        xPDO::OPT_CACHE_KEY => 'googledrive',
    ];

    /**
     * @var ?Client
     */
    private ?Client $client = null;

    private string $root = '';
    private string $urlPattern = '';

    public function initialize()
    {
        parent::initialize();

        $properties = $this->getProperties();
        $this->urlPattern = $properties['urlPattern']['value'];

        $client = $this->client();
        $drive = new Drive($client);

        $cache = new Pool($this->xpdo, self::$cacheOptions[xPDO::OPT_CACHE_KEY]);
        $adapter = new DriveAdapter($drive, [
            'cache' => $cache,
            'root' => $properties['root']['value'] ?? '',
            'maxItemsPerLevel' => $properties['maxItemsPerLevel']['value'] ?? 250,
        ]);
        $this->loadFlySystem($adapter);
        return true;
    }

    public function getTypeName(): string
    {
        $this->xpdo->lexicon->load('googledrivemediasource:default');
        return $this->xpdo->lexicon('googledrivemediasource.type');
    }

    public function getTypeDescription(): string
    {
        $this->xpdo->lexicon->load('googledrivemediasource:default');
        return $this->xpdo->lexicon('googledrivemediasource.description');
    }

    public function getBases($path = ''): array
    {
        return [
            'path' => $this->root,
            'pathIsRelative' => false,
            'pathAbsolute' => $this->root,
            'pathAbsoluteWithPath' => $path,
            'pathRelative' => $path,
            'urlIsRelative' => false,
            'url' => $this->urlPattern,
            'urlAbsolute' => $this->urlPattern,
            'urlAbsoluteWithPath' => $this->urlPattern . $path,
            'urlRelative' => $this->urlPattern . $path,
        ];
    }

    /**
     * Get the default properties for the filesystem media source type.
     *
     * @return array
     */
    public function getDefaultProperties(): array
    {

        $this->xpdo->lexicon->load('googledrivemediasource:default');

        return [
            'clientId' => [
                'name' => 'clientId',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.clientId_desc'),
                'type' => 'password',
                'options' => '',
                'value' => '',
//                'lexicon' => 'googledrivemediasource:default',
            ],
            'clientSecret' => [
                'name' => 'clientSecret',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.clientSecret_desc'),
                'type' => 'password',
                'options' => '',
                'value' => '',
//                'lexicon' => 'googledrivemediasource:default',
            ],
            'refreshToken' => [
                'name' => 'refreshToken',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.refreshToken_desc'),
                'type' => 'password',
                'options' => '',
                'value' => '',
            ],
            'root' => [
                'name' => 'root',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.root_desc'),
                'type' => 'list',
                'options' => [],
                'value' => '',
            ],
            'maxItemsPerLevel' => [
                'name' => 'maxItemsPerLevel',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.maxItemsPerLevel_desc'),
                'type' => 'numberfield',
                'options' => [],
                'value' => 250,
            ],
            'urlPattern' => [
                'name' => 'urlPattern',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.urlPattern_desc'),
                'type' => 'textfield',
                'options' => '',
                'value' => '',
            ],
        ];
    }


    public function prepareProperties(array $properties = []): array
    {

        if (empty($properties['urlPattern']['value'])) {
            $assets = $this->xpdo->getOption('googledrivemediasource.assets_url', null, $this->xpdo->getOption('assets_url') . 'components/googledrivemediasource/');
            $properties['urlPattern']['value'] = $assets . '?s={source}&id={id}';
        }

        $oAuth = $this->oauth2($properties);

        $properties['refreshToken']['desc'] = '<a href="' . $oAuth->buildFullAuthorizationUri() . '" class="x-btn primary-button">Authorize your Google Account</a>';
        if ($oAuth->getAccessToken()) {
            $properties['refreshToken']['desc'] = ' &check; Successfully authorized. To change accounts or re-connect: ' . $properties['refreshToken']['desc'];
        }

        // Look for auth codes passed in
        if (isset($_REQUEST['code'])) {
            $this->checkAuthorizationCode($oAuth, $_REQUEST['code']);
        }

        if (!$oAuth->getClientId() || !$oAuth->getClientSecret()) {
            unset($properties['refreshToken']);

            return parent::prepareProperties($properties);
        }

        if ($oAuth->getRefreshToken()) {
            if (isset($_GET['a']) && $_GET['a'] === 'source/update') {
                $properties['root']['options'] = [];

                $properties['root']['options'][] = [
                    'name' => '- root -',
                    'value' => 'root',
                ];

                $client = $this->client($oAuth);
                $drive = new Drive($client);
                $adapter = new DriveAdapter($drive);

                try {
                    $folders = $adapter->listContents('', false);

                    foreach ($folders as $folder) {
                        if (!$folder->isDir()) {
                            continue;
                        }
                        $properties['root']['options'][] = [
                            'name' => $folder->getName(),
                            'value' => $folder->path(),
                        ];
                    }

                } catch (UnableToRetrieveMetadata $e) {
                    $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, 'Received error loading root options for Google Drive Media Source: ' . $e->getMessage());
                    return parent::prepareProperties($properties);
                }


//            $drives = $adapter->getService()->teamdrives->listTeamdrives()->getTeamDrives();
//            foreach ($drives as $drive) {
//                $properties['root']['options'][] = [
//                    'name' => '[Team Drive] ' . $drive->name,
//                    'value' => 'teamdrive/' . $drive->id,
//                ];
////            }
            }

            return parent::prepareProperties($properties);
        }
        unset($properties['root']);

        return parent::prepareProperties($properties);
    }

    private function oauth2(array $properties): OAuth2
    {
        $oAuth = new OAuth2([
            'scope' => 'https://www.googleapis.com/auth/drive',
            'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
            'authorizationUri' => 'https://accounts.google.com/o/oauth2/auth',
//            'redirectUri' => 'https://' . $this->xpdo->getOption('http_host') . $this->xpdo->getOption('manager_url') . '?a=source/update&id=' . $this->get('id'),
            'redirectUri' => 'https://mm-commerce.eu.ngrok.io' . $this->xpdo->getOption('manager_url') . '?a=source/update&id=' . $this->get('id'),
            'clientId' => $properties['clientId']['value'] ?? '',
            'clientSecret' => $properties['clientSecret']['value'] ?? '',
            'refreshToken' => $properties['refreshToken']['value'] ?? '',
        ]);

        if ($tokens = $this->xpdo->getCacheManager()->get('access_token_' . $this->get('id'), self::$cacheOptions)) {
            $oAuth->updateToken($tokens);
        }

        $oAuth->setRefreshToken($properties['refreshToken']['value'] ?? '');

        return $oAuth;
    }

    private function client(?OAuth2 $oauth = null): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $oauth = $oauth ?? $this->oauth2($this->getProperties());
        $this->client = new Client([
            'client_id' => $oauth->getClientId(),
            'client_secret' => $oauth->getClientSecret(),
        ]);
        if ($tokens = $this->xpdo->getCacheManager()->get('access_token_' . $this->get('id'), self::$cacheOptions)) {
            $this->client->setAccessToken($tokens);
        }
        else {
            $tokens = $this->client->fetchAccessTokenWithRefreshToken($oauth->getRefreshToken());
//            var_dump($tokens);exit();

            $this->xpdo->getCacheManager()->set('access_token_' . $this->get('id'), $tokens, $tokens['expires_in'] - 60, self::$cacheOptions);
        }

        return $this->client;
    }

    private function checkAuthorizationCode(OAuth2 $oAuth, string $code): void
    {
        $oAuth->setCode($code);

        try {
            $oAuth->setGrantType('authorization_code');
            $tokens = $oAuth->fetchAuthToken();

            if (array_key_exists('refresh_token', $tokens)) {
                $properties['refreshToken']['value'] = $tokens['refresh_token'];
                $this->setProperties([
                    'refreshToken' => $tokens['refresh_token'],
                ]);
                $this->save();
            }

            if (array_key_exists('access_token', $tokens)) {
                $this->xpdo->getCacheManager()->set('access_token_' . $this->get('id'), $tokens, $tokens['expires_in'], self::$cacheOptions);
            }

        } catch (\Exception $e) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[GoogleDriveMediaSource] Received oAuth error when verifying token: ' . $e->getMessage());
        }
    }

    public function getContainerList($path)
    {
        $properties = $this->getPropertyListWithDefaults();
        $path = $this->postfixSlash($path);
        if ($path == DIRECTORY_SEPARATOR || $path == '\\') {
            $path = '';
        }

        $bases = $this->getBases($path);
        $imageExtensions = explode(',', $properties['imageExtensions']);
        $skipFiles = $this->getSkipFilesArray($properties);
        $allowedExtensions = $this->getAllowedExtensionsArray($properties);

        $directories = $dirNames = $files = $fileNames = [];

        if (!empty($path)) {

            // Ensure the provided path can be read.
            try {
                $dir = $this->adapter->get($path);
            } catch (FilesystemException | UnableToRetrieveMetadata $e) {
                $this->addError('path', $e->getMessage());
                $this->xpdo->log(modX::LOG_LEVEL_ERROR, $e->getMessage());
                return [];
            }

            if (!($dir instanceof Directory)) {
                $this->addError('path', $this->xpdo->lexicon('file_folder_err_invalid'));
                return [];
            }

        }

        try {
            $re = '#^(.*?/|)(' . implode('|', array_map('preg_quote', $skipFiles)) . ')/?$#';
            $contents = $this->filesystem->listContents($path);
            $contents->filter(function(StorageAttributes $attributes) use ($re) {
                return !preg_match($re, $attributes->path());
            })
                ->filter(function(StorageAttributes $attributes) use ($properties) {
                    if ($attributes instanceof Directory) {
                        return $this->hasPermission('directory_list');
                    }
                    if ($attributes instanceof File) {
                        return $this->hasPermission('file_list') && !$properties['hideFiles'];
                    }

                    return false;
                });

            /** @var File|Directory $object */
            foreach ($contents as $object) {
                $path = $object->path();
                $id = $object->getId();
                $name = $object->getName();

                if ($object instanceof Directory) {
                    $cls = $this->getExtJSDirClasses();
                    $dirNames[] = strtoupper($name);
                    $visibility = $this->visibility_dirs ? $this->getVisibility($object['path']) : Visibility::PRIVATE;
                    $directories[$id] = [
                        'id' => $id,
                        'sid' => $this->get('id'),
                        'text' => $name,
                        'cls' => implode(' ', $cls),
                        'iconCls' => 'icon icon-folder',
                        'type' => 'dir',
                        'leaf' => false,
                        'path' => $path,
                        'pathRelative' => $path,
                        'menu' => [],
                    ];
                    if ($this->visibility_dirs && $visibility) {
                        $directories[$id]['visibility'] = $visibility;
                    }
                    $directories[$id]['menu'] = [
                        'items' => $this->getListDirContextMenu(), // @todo
                    ];

                }
                elseif ($object instanceof File) {
                    $files[$id] = $this->_buildFileList($object, '', $imageExtensions, $bases, $properties);
                }
            }

            $ls = [];
            // now sort files/directories
            array_multisort($dirNames, SORT_ASC, SORT_STRING, $directories);
            foreach ($directories as $dir) {
                $ls[] = $dir;
            }

//            array_multisort($fileNames, SORT_ASC, SORT_STRING, $files);
            foreach ($files as $file) {
                $ls[] = $file;
            }

            return $ls;

        } catch (FilesystemException $e) {
            $this->addError('path', $e->getMessage());
            $this->xpdo->log(modX::LOG_LEVEL_ERROR, $e->getMessage());
            return [];
        }
    }

    protected function _buildFileList(File $file, $ext, $imageExtensions, $bases, $properties)
    {
        $path = $file->path();

        $editAction = $this->getEditActionId();
        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $driveCapabilities = $file->file->getCapabilities();
        $id = $file->getId();
        $mime = $file->mimeType();

        $cls = [];


        if (!empty($properties['currentFile']) && $properties['currentFile'] === $id && $properties['currentAction'] === $editAction) {
            $cls[] = 'active-node';
        }
        if ($driveCapabilities->canDelete && $canRemove && $this->hasPermission('file_remove')) {
            $cls[] = 'premove';
        }
        if ($driveCapabilities->canEdit && $canSave && $this->hasPermission('file_update')) {
            $cls[] = 'pupdate';
        }
        $page = null;
        if (!$this->isFileBinary($path)) {
            $page = !empty($editAction)
                ? '?a=' . $editAction . '&file=' . $id . '&wctx=' . $this->ctx->get('key') . '&source=' . $this->get('id')
                : null;
        }

        $url = $this->getObjectUrl($id);
        $file_list = [
            'id' => $id,
            'sid' => $this->get('id'),
            'text' => $file->getName(),
            'cls' => implode(' ', $cls),
            'iconCls' => 'icon icon-file icon-' . $this->_fileIcon((string)$file->file->fileExtension, (string)$mime),
            'type' => 'file',
            'leaf' => true,
            'page' => $page,
            'path' => $url,
            'pathRelative' => $url,
            'directory' => $file->path(),
            'url' => $url,
            'urlExternal' => $url,
            'urlAbsolute' => $url,
            'file' => rawurlencode($url),
            'visibility' => $file->visibility(),
        ];

        $file_list['menu'] = [
            'items' => $this->getFileMenu($file),
        ];


        $file_list['qtip'] = '';


        if (str_starts_with($mime, 'image/')) {
            $width = $this->ctx->getOption('filemanager_image_width', 400);
            $height = $this->ctx->getOption('filemanager_image_height', 300);

            $preview = $this->buildManagerImagePreview($file->path(), $mime, $width, $height, $bases);

            $file_list['qtip'] = '<img src="' . $preview['src'] . '" width="' . $preview['width'] . '" height="' . $preview['height'] . '" alt="' . htmlentities($file->getName()) . '" />';
        }

        return $file_list;
    }
    protected function buildFileBrowserViewList($path, $ext, $image_extensions, $bases, $properties)
    {
        $file = $this->adapter->get($path);

        $editAction = $this->getEditActionId();

        $page = null;
        if (!$this->isFileBinary($path)) {
            $page = !empty($editAction)
                ? '?a=' . $editAction . '&file=' . $path . '&wctx=' . $this->ctx->get('key') . '&source=' . $this->get('id')
                : null;
        }

        $width = $this->ctx->getOption('filemanager_image_width', 800);
        $height = $this->ctx->getOption('filemanager_image_height', 600);
        $original = $preview_image_info = [
            'width' => $width,
            'height' => $height,
        ];

        $thumb_width = $this->ctx->getOption('filemanager_thumb_width', 100);
        $thumb_height = $this->ctx->getOption('filemanager_thumb_height', 80);
        $thumb_image_info = [
            'width' => $thumb_width,
            'height' => $thumb_height,
        ];

        $mime = $file->mimeType();
        $preview = 0;
        if ($this->isFileImage($path, $image_extensions)) {
            $preview = 1;
            $preview_image_info = $this->buildManagerImagePreview($path, $ext, $width, $height, $bases, $properties);
            $thumb_image_info = $this->buildManagerImagePreview($path, $ext, $thumb_width, $thumb_height, $bases, $properties);
            $original = $this->getImageDimensions($path, $ext);
        }

        $lastmod = $file->lastModified();
        $size = $file->fileSize();

        $url = $this->getObjectUrl($file->getId());
        $file_list = [
            'id' => $file->getId(),
            'sid' => $this->get('id'),
            'name' => $file->getName(),
            'cls' => 'icon-' . $this->_fileIcon((string)$file->file->fileExtension, (string)$mime),
            'original_width' => $original['width'],
            'original_height' => $original['height'],
            // preview
            'preview' => $preview,
            'image' => $preview_image_info['src'] ?? '',
            'image_width' => $preview_image_info['width'],
            'image_height' => $preview_image_info['height'],
            // thumb
            'thumb' => $thumb_image_info['src'] ?? '',
            'thumb_width' => $thumb_image_info['width'],
            'thumb_height' => $thumb_image_info['height'],

            'url' => $url,
            'relativeUrl' => $url,
            'fullRelativeUrl' => $url,
            'ext' => $file->file->fileExtension ?? $ext,
            'pathname' => $url,
            'pathRelative' => $url,

            'lastmod' => $lastmod,
            'disabled' => false,
            'visibility' => $file->visibility(),
            'leaf' => true,
            'page' => $page,
            'size' => $size,
            'menu' => $this->getFileMenu($file),
        ];

        return $file_list;
    }

    public function getObjectContents($path)
    {
        $a = parent::getObjectContents($path);

        if (!empty($a)) {
            $file = $this->adapter->get($path);
            $a['basename'] = $file->getName();
            $a['name'] = $file->getName();
        }

        return $a;
    }

    protected function getFileMenu(File $file)
    {
        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $canView = $this->checkPolicy('view');

        $menu = [];

        if ($canView && $file->file->webViewLink) {
            $menu[] = [
                'text' => 'Open in Google Drive',
                // @todo figure out how to set a custom handler correctly
//                'handler' => "function a () { console.log(document); }",
//                'handler' => 'function openDrive () { window.open("' . $file->file->webViewLink . '"); }'
            ];
            $menu[] = '-';
        }


        $mime = $file->mimeType();
        $editable = !$this->isFileBinary($file->getId());

        if ($this->hasPermission('file_update') && $canSave) {
            if ($editable) {
                $menu[] = [
                    'text' => $this->xpdo->lexicon('file_edit'),
                    'handler' => 'this.editFile',
                ];
                $menu[] = [
                    'text' => $this->xpdo->lexicon('quick_update_file'),
                    'handler' => 'this.quickUpdateFile',
                ];
            }
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_rename'),
                'handler' => 'this.renameFile',
            ];
        }

        $canDownload = $this->hasPermission('file_view')
            && $canView
            && !str_starts_with($mime, 'application/vnd.google-apps');
        if ($canDownload) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_download'),
                'handler' => 'this.downloadFile',
            ];
        }

        if ($mime === 'application/vnd.google-apps.document') {
            $menu[] = [
                'text' => 'Download as PDF', // application/pdf
                // @todo handler
            ];
            $menu[] = [
                'text' => 'Download as MS Word', // application/vnd.openxmlformats-officedocument.wordprocessingml.document
                // @todo handler
            ];
        }
        elseif ($mime === 'application/vnd.google-apps.spreadsheet') {
            $menu[] = [
                'text' => 'Download as PDF', // application/pdf
                // @todo handler
            ];
            $menu[] = [
                'text' => 'Download as MS Excel', // application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
                // @todo handler
            ];
        }
        elseif ($mime === 'application/vnd.google-apps.presentation') {
            $menu[] = [
                'text' => 'Download as PDF', // application/pdf
                // @todo handler
            ];
            $menu[] = [
                'text' => 'Download as MS PowerPoint', // application/vnd.openxmlformats-officedocument.presentationml.presentation
                // @todo handler
            ];
        }


        if ($this->hasPermission('file_view')) {
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_copy_path'),
                'handler' => 'this.copyRelativePath',
            ];
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_open'),
                'handler' => 'this.openFile',
            ];
        }
        if ($canRemove && $this->hasPermission('file_remove')) {
            $menu[] = '-';
            $menu[] = [
                'text' => $this->xpdo->lexicon('file_remove'),
                'handler' => 'this.removeFile',
            ];
        }

        return $menu;
    }
    protected function getImageDimensions($path, $ext): bool|array
    {
        try {
            $obj = $this->adapter->get($path);
            if (!$obj->isFile()) {
                return false;
            }

            $meta = $obj->file->getImageMediaMetadata();
            if ($meta && $meta->width && $meta->height) {
                return [
                    'width' => $meta->width,
                    'height' => $meta->height,
                ];
            }
        } catch (UnableToRetrieveMetadata $e) {
        }
        return false;
    }
    public function getObjectsInContainer($path)
    {
        $properties = $this->getPropertyListWithDefaults();
        $path = $this->postfixSlash($path);
        $bases = $this->getBases($path);

        $imageExtensions = explode(',', $properties['imageExtensions']);
        $allowedExtensions = $this->getAllowedExtensionsArray($properties);

        $files = $fileNames = [];

        if (!empty($path) && $path !== DIRECTORY_SEPARATOR) {

            try {
                $mimeType = $this->filesystem->mimeType($path);
            } catch (FilesystemException | UnableToRetrieveMetadata $e) {
                $this->addError('path', $e->getMessage());
                $this->xpdo->log(modX::LOG_LEVEL_ERROR, $e->getMessage());
                return [];

            }

            // Ensure this is a directory.
            if ($mimeType !== 'directory') {
                $this->addError('path', $this->xpdo->lexicon('file_folder_err_invalid'));
                return [];
            }
        }

        try {
//            /** @var File[]|Directory[] $contents */
            $contents = $this->filesystem->listContents($path);
        } catch (FilesystemException $e) {
            $this->addError('path', $e->getMessage());
            $this->xpdo->log(modX::LOG_LEVEL_ERROR, $e->getMessage());
            return [];
        }
        foreach ($contents as $object) {
            if ($object instanceof Directory && !$this->hasPermission('directory_list')) {
                continue;
            }

            if ($object instanceof File && !$properties['hideFiles'] && $this->hasPermission('file_list')) {
                // ----- start of Google Drive specific changes -----

                // Turn extensions into mime types
                if (!empty($allowedExtensions)) {
                    $allowedExtensions = array_map(static function ($ext) {
                        return match ($ext) {
                            'png', 'jpeg', 'bmp', 'gif', 'tif' => 'image/' . $ext,
                            'jpg' => 'image/jpeg',
                            'svg' => 'image/svg+xml',
                            default => $ext,
                        };
                    }, $allowedExtensions);
                }

                $mimeType = $object->mimeType();
                $this->xpdo->log(1, print_r($allowedExtensions, true) . ' => ' . $mimeType);
                if (!empty($allowedExtensions) && !in_array($mimeType, $allowedExtensions, true)) {
                    continue;
                }
                $fileNames[] = strtoupper($object['path']);

                $files[$object['path']] = $this->buildFileBrowserViewList($object['path'], '', $imageExtensions, $bases, $properties);
            }
        }

        $ls = [];
        // now sort files/directories
        array_multisort($fileNames, SORT_ASC, SORT_STRING, $files);
        foreach ($files as $file) {
            $ls[] = $file;
        }

        return $ls;
    }

    protected function buildManagerImagePreview($path, $ext, $width, $height, $bases, $properties = []): array
    {
        return [
            'src' => $this->getObjectUrl($path),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param string $path
     * @return File|Directory
     * @throws InvalidArgumentException
     * @throws UnableToRetrieveMetadata
     */
    public function getDriveFile(string $path): File|Directory
    {
        return $this->adapter->get($path);
    }

    public function getObjectUrl($object = ''): ?string
    {
        try {
            $file = $this->adapter->get($object);
        } catch (InvalidArgumentException $e) {
            return null;
        }

        if ($file instanceof Directory) {
            return null;
        }

        return str_replace([
            '{source}',
            '{id}'
        ], [
            $this->get('id'),
            $file->getId(),
        ], $this->urlPattern);
    }


    protected function getListFileContextMenu($path, $editable = true, $data = [], )
    {
        // Make sure this inherited method isn't accidentally called anywhere else
        return [];
    }

    /**
     * Overridden method because otherwise it avoids extension-less files from being deleted
     * or renamed/removed. As Drive is essentially a static host, allow anything.
     *
     * @todo Consider implementing alternate checks in writing methods to still offer some security
     *
     * @param $filename
     * @return bool
     */
    protected function checkFileType($filename): bool
    {
        return true;
    }

    private function _fileIcon(string $fileExtension, ?string $mime = '')
    {
        if (!empty($fileExtension)) {
            return $fileExtension;
        }

        return match ($mime) {
            'application/vnd.google-apps.document' => 'docx',
//            'application/vnd.google-apps.drive-sdk', 'application/vnd.google-apps.shortcut' => 'drive-shortcut',
//            'application/vnd.google-apps.form' => 'drive-form',
//            'application/vnd.google-apps.map' => 'drive-map',
            'application/vnd.google-apps.photo' => 'png',
            'application/vnd.google-apps.presentation' => 'ppt',
            'application/vnd.google-apps.spreadsheet' => 'xls',
            default => 'unknown',
        };
    }
}
