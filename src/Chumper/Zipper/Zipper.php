<?php namespace Chumper\Zipper;


use Chumper\Zipper\Repositories\RepositoryInterface;
use Exception;
use Illuminate\Filesystem\Filesystem;

/**
 * This Zipper class is a wrapper around the ZipArchive methods with some handy functions
 *
 * Class Zipper
 * @package Chumper\Zipper
 */
class Zipper
{

    /**
     * Constant for extracting
     */
    const WHITELIST = 1;

    /**
     * Constant for extracting
     */
    const BLACKLIST = 2;

    /**
     * Constant for matching only strictly equal file names
     */
    const EXACT_MATCH = 4;

    /**
     * @var string Represents the current location in the archive
     */
    private $currentFolder = '';

    /**
     * @var Filesystem Handler to the file system
     */
    private $file;

    /**
     * @var RepositoryInterface Handler to the archive
     */
    private $repository;

    /**
     * @var string The path to the current zip file
     */
    private $filePath;

    /**
     * Constructor
     *
     * @param Filesystem $fs
     */
    function __construct(Filesystem $fs = null)
    {
        $this->file = $fs ? $fs : new Filesystem();
    }

    /**
     * Create a new zip Archive if the file does not exists
     * opens a zip archive if the file exists
     *
     * @param $pathToFile string The file to open
     * @param RepositoryInterface|string $type The type of the archive, defaults to zip, possible are zip, phar
     *
     * @return $this Zipper instance
     * @throws \Exception
     * @throws \InvalidArgumentException
     */
    public function make($pathToFile, $type = 'zip')
    {
        $new = $this->createArchiveFile($pathToFile);
        $this->filePath = $pathToFile;

        $objectOrName = $type;
        if (is_string($type)) {
            $objectOrName = 'Chumper\Zipper\Repositories\\' . ucwords($type) . 'Repository';
        }

        if (!is_subclass_of($objectOrName, 'Chumper\Zipper\Repositories\RepositoryInterface')) {
            throw new \InvalidArgumentException("Class for '{$objectOrName}' must implement RepositoryInterface interface");
        }

        if (is_string($objectOrName)) {
            $this->repository = new $objectOrName($pathToFile, $new);
        } else {
            $this->repository = $type;
        }

        return $this;
    }

    /**
     * Create a new zip archive or open an existing one
     *
     * @param $pathToFile
     * @return $this
     */
    public function zip($pathToFile)
    {
        $this->make($pathToFile);
        return $this;
    }

    /**
     * Create a new phar file or open one
     *
     * @param $pathToFile
     * @return $this
     */
    public function phar($pathToFile)
    {
        $this->make($pathToFile, 'phar');
        return $this;
    }

    /**
     * Create a new rar file or open one
     *
     * @param $pathToFile
     * @return $this
     */
    public function rar($pathToFile)
    {
        $this->make($pathToFile, 'rar');
        return $this;
    }

    /**
     * Extracts the opened zip archive to the specified location <br/>
     * you can provide an array of files and folders and define if they should be a white list
     * or a black list to extract. By default this method compares file names using "string starts with" logic
     *
     * @param $path string The path to extract to
     * @param array $files An array of files
     * @param int $methodFlags The Method the files should be treated
     * @throws \Exception
     */
    public function extractTo($path, array $files = array(), $methodFlags = Zipper::BLACKLIST)
    {
        if (!$this->file->exists($path))
            $this->file->makeDirectory($path, 0755, true);

        if ($methodFlags & Zipper::EXACT_MATCH) {
            $matchingMethod = function ($haystack, $needles) {
                return in_array($haystack, $needles, true);
            };
        } else {
            $matchingMethod = function ($haystack, $needles) {
                return starts_with($haystack, $needles);
            };
        }

        if ($methodFlags & Zipper::WHITELIST) {
            $this->extractWithWhiteList($path, $files, $matchingMethod);
        } else {
            $this->extractWithBlackList($path, $files, $matchingMethod);
        }
    }

    /**
     * Gets the content of a single file if available
     *
     * @param $filePath string The full path (including all folders) of the file in the zip
     * @throws \Exception
     * @return mixed returns the content or throws an exception
     */
    public function getFileContent($filePath)
    {

        if ($this->repository->fileExists($filePath) === false)
            throw new Exception(sprintf('The file "%s" cannot be found', $filePath));

        return $this->repository->getFileContent($filePath);
    }

    /**
     * Add one or multiple files to the zip.
     *
     * @param $pathToAdd array|string An array or string of files and folders to add
     * @return $this Zipper instance
     */
    public function add($pathToAdd, $fileName = null)
    {
        if (is_array($pathToAdd)) {
            foreach ($pathToAdd as $dir) {
                $this->add($dir);
            }
        } else if ($this->file->isFile($pathToAdd)) {
            if ($fileName)
                $this->addFile($pathToAdd, $fileName);
            else
                $this->addFile($pathToAdd);
        } else
            $this->addDir($pathToAdd);

        return $this;
    }

    /**
     * Add an empty directory
     *
     * @param $dirName
     * @return void
     */
    public function addEmptyDir($dirName)
    {
        $this->repository->addEmptyDir($dirName);

        return $this;
    }

    /**
     * Add a file to the zip using its contents
     *
     * @param $filename string The name of the file to create
     * @param $content string The file contents
     * @return $this Zipper instance
     */
    public function addString($filename, $content)
    {
        $this->addFromString($filename, $content);

        return $this;
    }


    /**
     * Gets the status of the zip.
     *
     * @return integer The status of the internal zip file
     */
    public function getStatus()
    {
        return $this->repository->getStatus();
    }

    /**
     * Remove a file or array of files and folders from the zip archive
     *
     * @param $fileToRemove array|string The path/array to the files in the zip
     * @return $this Zipper instance
     */
    public function remove($fileToRemove)
    {
        if (is_array($fileToRemove)) {
            $self = $this;
            $this->repository->each(function ($file) use ($fileToRemove, $self) {
                if (starts_with($file, $fileToRemove)) {
                    $self->getRepository()->removeFile($file);
                }
            });
        } else
            $this->repository->removeFile($fileToRemove);

        return $this;
    }

    /**
     * Returns the path of the current zip file if there is one.
     * @return string The path to the file
     */
    public function getFilePath()
    {
        return $this->filePath;
    }

    /**
     * Sets the password to be used for decompressing
     *
     * @param $password
     * @return boolean
     */
    public function usePassword($password)
    {
        return $this->repository->usePassword($password);
    }

    /**
     * Closes the zip file and frees all handles
     */
    public function close()
    {
        if(!is_null($this->repository))
            $this->repository->close();
        $this->filePath = "";
    }

    /**
     * Sets the internal folder to the given path.<br/>
     * Useful for extracting only a segment of a zip file.
     * @param $path
     * @return $this
     */
    public function folder($path)
    {
        $this->currentFolder = $path;
        return $this;
    }

    /**
     * Resets the internal folder to the root of the zip file.
     *
     * @return $this
     */
    public function home()
    {
        $this->currentFolder = '';
        return $this;
    }

    /**
     * Deletes the archive file
     */
    public function delete()
    {
        if(!is_null($this->repository))
            $this->repository->close();

        $this->file->delete($this->filePath);
        $this->filePath = "";
    }

    /**
     * Get the type of the Archive
     *
     * @return string
     */
    public function getArchiveType()
    {
        return get_class($this->repository);
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        if(!is_null($this->repository))
            $this->repository->close();
    }

    /**
     * Get the current internal folder pointer
     *
     * @return string
     */
    public function getCurrentFolderPath()
    {
        return $this->currentFolder;
    }

    /**
     * Checks if a file is present in the archive
     *
     * @param $fileInArchive
     * @return bool
     */
    public function contains($fileInArchive)
    {
        return $this->repository->fileExists($fileInArchive);
    }

    /**
     * @return RepositoryInterface
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * @return Filesystem
     */
    public function getFileHandler()
    {
        return $this->file;
    }

    /**
     * Gets the path to the internal folder
     *
     * @return string
     */
    public function getInternalPath()
    {
        return empty($this->currentFolder) ? '' : $this->currentFolder . '/';
    }

    //---------------------PRIVATE FUNCTIONS-------------

    /**
     * @param $pathToZip
     * @throws \Exception
     * @return bool
     */
    private function createArchiveFile($pathToZip)
    {

        if (!$this->file->exists($pathToZip)) {
            if (!$this->file->exists(dirname($pathToZip)))
                $this->file->makeDirectory(dirname($pathToZip), 0755, true);

            if (!$this->file->isWritable(dirname($pathToZip)))
                throw new Exception(sprintf('The path "%s" is not writeable', $pathToZip));

            return true;
        }
        return false;
    }

    /**
     * @param $pathToDir
     */
    private function addDir($pathToDir)
    {
        // First go over the files in this directory and add them to the repository.
        foreach ($this->file->files($pathToDir) as $file) {
            $this->addFile($pathToDir . '/' . basename($file));
        }

        // Now let's visit the subdirectories and add them, too.
        foreach ($this->file->directories($pathToDir) as $dir) {
            $old_folder = $this->currentFolder;
            $this->currentFolder = empty($this->currentFolder) ? basename($dir) : $this->currentFolder . '/' . basename($dir);
            $this->addDir($pathToDir . '/' . basename($dir));
            $this->currentFolder = $old_folder;
        }
    }

    /**
     * Add the file to the zip
     *
     * @param $pathToAdd
     */
    private function addFile($pathToAdd, $fileName = null)
    {
        $info = pathinfo($pathToAdd);

        if (!$fileName)
            $fileName = isset($info['extension']) ?
                $info['filename'] . '.' . $info['extension'] :
                $info['filename'];

        $this->repository->addFile($pathToAdd, $this->getInternalPath() . $fileName);
    }

    /**
     * Add the file to the zip from content
     *
     * @param $filename
     * @param $content
     */
    private function addFromString($filename, $content)
    {
        $this->repository->addFromString($this->getInternalPath() . $filename, $content);
    }


    /**
     * @param $path
     * @param $filesArray
     * @param callable $matchingMethod
     */
    private function extractWithBlackList($path, $filesArray, callable $matchingMethod)
    {
        $self = $this;
        $this->repository->each(function ($fileName) use ($path, $filesArray, $matchingMethod, $self) {
            $oriName = $fileName;

            $currentPath = $self->getCurrentFolderPath();
            if (!empty($currentPath) && !starts_with($fileName, $currentPath)) {
                return;
            }

            $tmpPath = str_replace($self->getInternalPath(), '', $fileName);
            if ($matchingMethod($tmpPath, $filesArray)) {
                return;
            }

            // We need to create the directory first in case it doesn't exist
            $full_path = $path . DIRECTORY_SEPARATOR . $tmpPath;
            $dir = substr($full_path, 0, strrpos($full_path, '/'));
            if(!is_dir($dir)) {
                $self->getFileHandler()->makeDirectory($dir, 0777, true, true);
            }

            $toPath = $path . DIRECTORY_SEPARATOR . $tmpPath;
            $fileStream = $self->getRepository()->getFileStream($oriName);
            $self->getFileHandler()->put($toPath, $fileStream);

        });
    }

    /**
     * @param $path
     * @param $filesArray
     * @param callable $matchingMethod
     */
    private function extractWithWhiteList($path, $filesArray, callable $matchingMethod)
    {
        $self = $this;
        $this->repository->each(function ($fileName) use ($path, $filesArray, $matchingMethod, $self) {
            $oriName = $fileName;

            $currentPath = $self->getCurrentFolderPath();
            if (!empty($currentPath) && !starts_with($fileName, $currentPath))
                return;

            $tmpPath = str_replace($self->getInternalPath(), '', $fileName);
            if ($matchingMethod($tmpPath, $filesArray)) {
                $tmpPath = str_replace($self->getInternalPath(), '', $fileName);

                // We need to create the directory first in case it doesn't exist
                $full_path = $path . DIRECTORY_SEPARATOR . $tmpPath;
                $dir = substr($full_path, 0, strrpos($full_path, DIRECTORY_SEPARATOR));
                if(!is_dir($dir)) {
                    //FIXME check return boolean | force=true does not necessarily create directory. e.g. lack of privileges/$dir is not a valid string for directory
                    $self->getFileHandler()->makeDirectory($dir, 0777, true, true);
                }

                $self->getFileHandler()->put($path . '/' . $tmpPath, $self->getRepository()->getFileStream($oriName));
            }
        });
    }

    /**
     * List files that are within the archive
     *
     * @return array
     */
    public function listFiles()
    {
        $filesList = array();
        $this->repository->each(
            function ($file) use (&$filesList) {
                $filesList[] = $file;
            }
        );

        return $filesList;
    }
}
