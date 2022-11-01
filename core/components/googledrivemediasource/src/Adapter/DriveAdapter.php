<?php

namespace modmore\GoogleDriveMediaSource\Adapter;

use Google\Exception;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class DriveAdapter implements FilesystemAdapter
{
    protected const DIRECTORY_MIME = 'application/vnd.google-apps.folder';
    protected const LIST_FIELDS = 'files(id,mimeType,createdTime,modifiedTime,name,parents,permissions,size,webContentLink,webViewLink,iconLink,contentHints,imageMediaMetadata,capabilities,exportLinks,resourceKey,fileExtension),nextPageToken';
    protected const GET_FIELDS = 'id,mimeType,createdTime,modifiedTime,name,parents,permissions,size,webContentLink,webViewLink,iconLink,contentHints,imageMediaMetadata,capabilities,exportLinks,resourceKey,fileExtension';

    private Drive $drive;
    private ?CacheItemPoolInterface $cache = null;
    private string $root;
    private int $maxItemsPerLevel = 250;

    private array $memoryCache = [];
    private File|Directory|null $lastObj = null;
    private array $parentMap = [];

    public function __construct(Drive $drive, array $config = [])
    {
        $this->drive = $drive;
        $this->root = $config['root'] ?? 'root';


        if (isset($config['cache']) && $config['cache'] instanceof CacheItemPoolInterface) {
            $this->cache = $config['cache'];
        }
        if (isset($config['maxItemsPerLevel']) && is_numeric($config['maxItemsPerLevel']) && abs($config['maxItemsPerLevel']) < 1000) {
            $this->maxItemsPerLevel = (int)abs($config['maxItemsPerLevel']);
        }
    }

    /**
     * @param string $path
     * @return File|Directory
     * @throws UnableToRetrieveMetadata
     * @throws InvalidArgumentException
     */
    public function get(string $path): File|Directory
    {
        $segments = explode('/', trim($path, '/'));
        $fileId = end($segments);

        if (isset($this->memoryCache[$fileId])) {
            return $this->memoryCache[$fileId];
        }

        $params = [
            'fields' => self::GET_FIELDS,
        ];

        $item = $this->cache ? $this->cache->getItem($fileId) : false;
        if ($item && $item->isHit()) {
            $obj = CacheableTrait::fromCacheArray($item->get());
            $this->memoryCache[$obj->getId()] = $obj;
            $this->lastObj = $obj;
            return $obj;
        }

        try {
            $file = $this->drive->files->get($fileId, $params);
        } catch (\Exception $e) {
            throw new UnableToRetrieveMetadata($e->getMessage());
        }

        $this->limitToRoot($file);

        $obj = $this->convertToFile($file);
        if ($this->cache && $item) {
            $item->set($obj->toCacheArray());
            $item->expiresAfter(new \DateInterval('PT5M'));
            $this->cache->save($item);
        }
        $this->memoryCache[$obj->getId()] = $obj;

        $this->lastObj = $obj;
        return $obj;
    }

    /**
     * @param string $path
     * @param string $query
     * @return array|Directory[]|File[]
     * @throws InvalidArgumentException
     * @throws UnableToRetrieveMetadata
     */
    public function list(string $path): array
    {
        $segments = explode('/', $path);
        $parent = end($segments) ?: 'root';
        if ($parent === 'root' || empty($parent)) {
            $parent = $this->root;
        }

        // Load the parent to ensure it is accessible and restricted to root
        $this->get($parent);

        $cacheKey = 'DIR-' . $this->root . '-' . $parent;
//        if (empty($query)) {
//            $cacheKey .= sha1($query);
//        }

        $item = $this->cache ? $this->cache->getItem($cacheKey) : false;
        if ($item && $item->isHit()) {
            $data = $item->get();

            $results = [];
            foreach ($data as $item) {
                $results[] = CacheableTrait::fromCacheArray($item);
            }
            return $results;
        }

        $results = $this->fetchList($parent);
        if ($item) {
            $cache = [];
            foreach ($results as $obj) {
                $objArray = $obj->toCacheArray();
                $cache[] = $objArray;

                // Pre-fill individual cache too
                $indivItem = $this->cache->getItem($obj->getId());
                $indivItem->set($objArray);
                $indivItem->expiresAfter(new \DateInterval('PT5M'));
                $this->cache->saveDeferred($indivItem);
            }

            $this->cache->commit();

            $item->set($cache);
            $item->expiresAfter(new \DateInterval('PT5M'));
            $this->cache->save($item);
        }

        return $results;
    }

    /**
     * @param string $parent
     * @param string $query
     * @return File[]|Directory[]
     * @throws UnableToRetrieveMetadata
     */
    private function fetchList(string $parent = 'root', string $query = ''): array
    {
        $results = [];

        $q = "trashed = false and '{$parent}' in parents";
        if (!empty($query)) {
            $q .= ' and (' . $query . ')';
        }

        $pageToken = null;
        do {
            $params = [
                'q' => $q,
                'pageSize' => 100,
                'orderBy' => 'folder, name',
                'spaces' => 'drive',
                'pageToken' => $pageToken,
                'fields' => self::LIST_FIELDS,
            ];

            try {
                $response = $this->drive->files->listFiles($params);
            } catch (\Exception $e) {
                throw new UnableToRetrieveMetadata($e->getMessage());
            }

            foreach ($response->getFiles() as $file) {
                $results[] = $this->convertToFile($file, $parent);
            }

            // Somewhere past ~ 500 items it consistently runs out of memory
//            $pageToken = null;
            $pageToken = $response->getNextPageToken();
        } while (!empty($pageToken) && count($results) < $this->maxItemsPerLevel);
        return $results;
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->get($path) instanceof StorageAttributes;
        } catch (UnableToRetrieveMetadata) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, $contents);
    }

    protected function upload(string $path, mixed $contents)
    {
        $segments = explode('/', trim($path, '/'));
        $name = array_pop($segments);
        $parent = end($segments) ?: $this->root;


        $srcFile = null;
        try {
            $srcFile = $this->get($path);
            if ($srcFile instanceof Directory) {
                $srcFile = null;
            }
        } catch (InvalidArgumentException|UnableToRetrieveMetadata) {
        }

        $stream = Utils::streamFor($contents);
        $size = $stream->getSize();

        $file = new DriveFile();
        if (!$srcFile) {
            $file->setName($name);
            $file->setParents([$parent]);

            $detector = new FinfoMimeTypeDetector();
            $mime = $detector->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
            $file->setMimeType($mime);
        }


        $params = [
            'data' => $stream,
            'uploadType' => 'media',
            'fields' => self::GET_FIELDS
        ];

        if ($srcFile) {
            $obj = $this->drive->files->update($srcFile->getId(), $file, $params);
            unset($this->memoryCache[$srcFile->getId()]);
        }
        else {
            $obj = $this->drive->files->create($file, $params);
        }

        if (!$obj) {
            throw new UnableToWriteFile(print_r($obj, true));
        }
        $this->lastObj = $this->convertToFile($obj);
        if ($this->cache) {
            $this->cache->deleteItem('DIR-' . $this->root . '-' . $parent);
            if ($srcFile) {
                $this->cache->deleteItem($srcFile->getId());
                $this->cache->deleteItem($srcFile->getId() . '_content');
            }
        }
    }

    public function read(string $path, string $exportMimeType = ''): string
    {
        $file = $this->get($path);
        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('Does not exist or is not a file');
        }

        $cacheKey = $file->getId() . '_content';
        if (!empty($exportMimeType)) {
            $cacheKey .= '_' . str_replace(['/', '+'], '-', $exportMimeType);
        }
        $item = $this->cache ? $this->cache->getItem($cacheKey) : false;
        if ($item && $item->isHit()) {
            return base64_decode($item->get());
        }

        $mime = $file->mimeType();

        if (str_starts_with($mime, 'application/vnd.google-apps')) {
            $response = $this->drive->files->export($file->getId(), $exportMimeType);
        }
        else {
            $response = $this->drive->files->get($file->getId(), [
                'alt' => 'media',
            ]);
        }

        if ($response) {
            $body = (string)$response->getBody();
            if ($item && $this->cache) {
                $item->set(base64_encode($body));
                $item->expiresAfter(new \DateInterval('PT1M'));
                $this->cache->save($item);
            }
            return $body;
        }
        return '';
    }

    public function readStream(string $path)
    {
        // @todo make this an actual streaming implementation that streams directly from Drive
        $content = $this->read($path);
        $stream = fopen('php://memory','rb+');
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }

    public function delete(string $path): void
    {
        if (empty($path) || $path === $this->root) {
            throw UnableToDeleteFile::atLocation($path, 'Unable to delete root');
        }
        try {
            $item = $this->get($path);
        } catch (UnableToRetrieveMetadata) {
            throw UnableToDeleteFile::atLocation($path, 'File or directory does not exist');
        }
        if ($item instanceof Directory) {
            throw UnableToDeleteFile::atLocation($path, 'Provided path is a file, not a directory.');
        }

        unset($this->memoryCache[$item->getId()]);

        $file = new DriveFile();
        $file->setTrashed(true);

        if (!$result = $this->drive->files->update($item->getId(), $file)) {
            throw UnableToDeleteFile::atLocation($path, 'Received error marking item as trashed: ' . print_r($result, true));
        }
        $this->lastObj = $item;
        if ($this->cache) {
            $this->cache->deleteItem($item->getId());
            $this->cache->deleteItem($item->getId() . '_content');

            // Remove relevant parent listings from the cache
            foreach ($item->file->getParents() as $parentId) {
                $this->cache->deleteItem('DIR-' . $this->root . '-' . $parentId);
            }
        }

    }

    public function deleteDirectory(string $path): void
    {
        if (empty($path) || $path === $this->root) {
            throw UnableToDeleteDirectory::atLocation($path, 'Unable to delete root');
        }
        try {
            $item = $this->get($path);
        } catch (UnableToRetrieveMetadata) {
            throw UnableToDeleteDirectory::atLocation($path, 'File or directory does not exist');
        }
        if ($item instanceof File) {
            throw UnableToDeleteDirectory::atLocation($path, 'Provided path is a file, not a directory.');
        }

        unset($this->memoryCache[$item->getId()]);

        $file = new DriveFile();
        $file->setTrashed(true);

        if (!$result = $this->drive->files->update($item->getId(), $file)) {
            throw UnableToDeleteDirectory::atLocation($path, 'Received error marking item as trashed: ' . print_r($result, true));
        }
        $this->lastObj = $item;
        if ($this->cache) {
            $this->cache->deleteItem($item->getId());
            // Remove relevant parent listings from the cache
            foreach ($item->file->getParents() as $parentId) {
                $this->cache->deleteItem('DIR-' . $this->root . '-' . $parentId);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $segments = explode('/', trim($path, '/'));
        $name = array_pop($segments);
        $parent = end($segments) ?: $this->root;

        $file = new DriveFile();
        $file->setName($name);
        $file->setParents([$parent]);
        $file->setMimeType('application/vnd.google-apps.folder');

        $obj = $this->drive->files->create($file, [
            'fields' => self::GET_FIELDS
        ]);

        if (!$obj->getId()) {
            throw new UnableToCreateDirectory(print_r($obj, true));
        }

        $this->lastObj = $this->convertToFile($obj);
        // Delete the directory listing of the parent from cache
        if ($this->cache) {
            $this->cache->deleteItem('DIR-' . $this->root . '-' . $parent);
            unset($this->memoryCache[$parent]);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw new UnableToSetVisibility('Setting visibility is not supported by this driver.');
    }

    public function visibility(string $path): File
    {
        $file = $this->get($path);
        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('File does not exist or is a directory');
        }
        return $file;
    }

    public function mimeType(string $path): FileAttributes
    {
        $file = $this->get($path);
        if ($file instanceof Directory) {
            return new FileAttributes($path, null, null, null, 'directory');
        }

        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('File does not exist');
        }
        return new FileAttributes($path, null, null, null, $file->mimeType());
    }

    public function lastModified(string $path): File
    {
        $file = $this->get($path);
        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('File does not exist or is a directory');
        }
        return $file;
    }

    public function fileSize(string $path): File
    {
        $file = $this->get($path);
        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('File does not exist or is a directory');
        }
        return $file;
    }

    /**
     * @param string $path
     * @param bool $deep
     * @return File[]|Directory[]|iterable
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $list = $this->list($path);
        foreach ($list as $item) {
            yield $item;
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $sourceFile = $this->get($source);
        } catch (InvalidArgumentException|UnableToRetrieveMetadata) {
            throw new UnableToMoveFile('Source file does not exist');
        }
        try {
            $segments = explode('/', $destination);
            $filename = array_pop($segments);
            $destination = (string)end($segments);
            if (empty($destination)) {
                $destination = $this->root;
            }
            $targetParent = $this->get($destination);
        } catch (InvalidArgumentException|UnableToRetrieveMetadata) {
            throw new UnableToMoveFile('Target parent ' . $destination . ' does not exist');
        }

        $patchObj = new DriveFile();
        $params = [];

        // Moving a file to a different container
        if (!in_array($targetParent->getId(), $sourceFile->file->parents, true)) {
            $params['addParents'] = $targetParent->getId();
            $parent['removeParents'] = implode(',', $sourceFile->file->getParents());
        }
        // Renaming a file - even tho the *name* (id) doesn't change
        if ($filename !== $sourceFile->getName() && $filename !== $sourceFile->getId()) {
            $patchObj->setName($filename);
        }

        try {
            $this->drive->files->update($sourceFile->getId(), $patchObj, $params );
        } catch (Exception $e) {
            throw new UnableToMoveFile($e->getMessage());
        }

        // Delete the directory listing cache of the item and both parents
        unset($this->memoryCache[$sourceFile->getId()], $this->memoryCache[$targetParent->getId()]);

        if ($this->cache) {
            $this->cache->deleteItem($sourceFile->getId());
            $this->cache->deleteItem('parent_of_' . $sourceFile->getId());
            $this->cache->deleteItem('DIR-' . $this->root . '-' . $targetParent->getId());
            foreach ($sourceFile->file->getParents() as $parent) {
                $this->cache->deleteItem('DIR-' . $this->root . '-' . $parent);
            }
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $sourceFile = $this->get($source);
        } catch (InvalidArgumentException|UnableToRetrieveMetadata) {
            throw new UnableToCopyFile('Source file does not exist');
        }
        try {
            $segments = explode('/', $destination);
            array_pop($segments);
            $destination = (string)end($segments);
            if (empty($destination)) {
                $destination = $this->root;
            }
            $targetParent = $this->get($destination);
        } catch (InvalidArgumentException|UnableToRetrieveMetadata) {
            throw new UnableToCopyFile('Target parent ' . $destination . ' does not exist');
        }

        $newFile = new DriveFile();
        $newFile->setParents([$targetParent->getId()]);
        try {
            $this->drive->files->copy($sourceFile->getId(), $newFile);
        } catch (Exception $e) {
            throw new UnableToCopyFile($e->getMessage());
        }

        // Delete the directory listing cache of the item and both parents
        unset($this->memoryCache[$targetParent->getId()]);
        if ($this->cache) {
            $this->cache->deleteItem('DIR-' . $this->root . '-' . $targetParent->getId());
        }
    }

    /**
     * @param DriveFile $file
     * @param string $parent
     * @return File|Directory
     */
    private function convertToFile(DriveFile $file, string $parent = 'root'): File|Directory
    {
        $path = ($parent !== 'root' ? $parent . '/' : '') . $file->getId();

        // In a search, directories appear with a mime type
        if ($file->getMimeType() === self::DIRECTORY_MIME) {
            return new Directory($file, $path);
        }
        // In a simple get by ID, they appear just with a different kind
        // and lack an ID
        if ($file->getKind() === 'drive#fileList') {
            $file->id = $parent;
            return new Directory($file, $path);
        }

        return new File($file, $path);
    }

    public function limitToRoot(DriveFile $driveFile): void
    {
        $file = $driveFile->getId();
        if ($file === $this->root) {
            return;
        }

        while ($parent = $this->getParent($file)) {
            if ($parent === $this->root) {
                return;
            }
            $file = $parent;
        }
        throw new UnableToRetrieveMetadata('Outside of allowed scope');
    }

    private function getParent(?string $fileId): ?string
    {
        if (empty($fileId)) {
            return null;
        }

        if (isset($this->parentMap[$fileId])) {
            return $this->parentMap[$fileId];
        }

        if ($this->cache) {
            $item = $this->cache->getItem('parent_of_' . $fileId);
            if ($item->isHit()) {
                return $item->get();
            }
        }

        try {
            $parent = $this->drive->files->get($fileId, [
                'fields' => 'id,name,parents'
            ]);
            $parentId = $parent && $parent->parents ?  reset($parent->parents) : null;
            $this->parentMap[$fileId] = $parentId;

            if ($this->cache && isset($item)) {
                $item->set($parentId);
                $item->expiresAfter(new \DateInterval('PT12H'));
                $this->cache->save($item);
            }
            return $parentId;
        } catch (Exception) {
            $this->parentMap[$fileId] = null;
            if ($this->cache && isset($item)) {
                $item->set(null);
                $item->expiresAfter(new \DateInterval('PT72H'));
                $this->cache->save($item);
            }
        }
        return null;
    }

    /**
     * Returns the last file or directory that was created/read/updated/deleted.
     *
     * Useful to get the ID (path) of a new file, for example.
     *
     * @return Directory|File|null
     */
    public function getLastItem(): File|Directory|null
    {
        return $this->lastObj;
    }

}