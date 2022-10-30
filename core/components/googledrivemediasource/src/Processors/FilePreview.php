<?php


namespace modmore\GoogleDriveMediaSource\Processors;

use modmore\GoogleDriveMediaSource\Adapter\Directory;
use modmore\GoogleDriveMediaSource\Adapter\File;
use modmore\GoogleDriveMediaSource\Model\GoogleDriveMediaSource;
use MODX\Revolution\Processors\Processor;
use MODX\Revolution\Sources\modMediaSource;

class FilePreview extends Processor
{
    private GoogleDriveMediaSource $source;
    private Directory|File $file;

    public function initialize()
    {
        $sourceId = $this->getProperty('source', 0);
        /** @var modMediaSource|GoogleDriveMediaSource $source */
        $source = $this->modx->getObject(modMediaSource::class, ['id' => $sourceId]);
        if (!($source instanceof GoogleDriveMediaSource) || !$source->initialize()) {
            return false;
        }

        $this->source = $source;

        $fileId = (string)$this->getProperty('file');
        $this->file = $this->source->getDriveFile($fileId);
        return true;
    }

    public function process()
    {
        if ($this->file instanceof Directory) {
            return $this->failure('Requested file is a directory.');
        }

        $mime = $this->file->mimeType();

        if (str_starts_with($mime, 'image/')) {
            header("Content-Type: $mime");
            @session_write_close();
            echo $this->source->getObjectContents($this->file->getId())['content'];
//            var_dump($mime);exit();
            exit();
        }

        return 'Unsupported file type';

        $dump = $this->file->toCacheArray();
        $dump = print_r($dump, true);

        return <<<HTML
<pre><code>$dump</code></pre>
HTML;

    }
}

return FilePreview::class;
