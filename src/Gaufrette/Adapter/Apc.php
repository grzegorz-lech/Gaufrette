<?php

namespace Gaufrette\Adapter;

use Gaufrette\Util;
use Gaufrette\Exception;

/**
 * Apc adapter, a non-persistent adapter for when this sort of thing is appropriate
 *
 * @author Alexander Deruwe <alexander.deruwe@gmail.com>
 * @author Antoine Hérault <antoine.herault@gmail.com>
 */
class Apc extends Base
{
    protected $prefix;
    protected $ttl;

    /**
     * Constructor
     *
     * @throws \RuntimeException
     * @param string $prefix to avoid conflicts between filesystems
     * @param int $ttl time to live, default is 0
     */
    public function __construct($prefix, $ttl = 0)
    {
        if (!extension_loaded('apc')) {
            throw new \RuntimeException('Unable to use Gaufrette\Adapter\Apc as the APC extension is not available.');
        }

        $this->prefix = $prefix;
        $this->ttl = $ttl;
    }

    /**
     * {@inheritDoc}
     */
    public function read($key)
    {
        $this->assertExists($key);

        $content = apc_fetch($this->computePath($key));

        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read the \'%s\' file.', $key));
        }

        return $content;
    }

    /**
     * {@inheritDoc}
     */
    public function write($key, $content, array $metadata = null)
    {
        $result = apc_store($this->computePath($key), $content, $this->ttl);

        if (false === $result) {
            throw new \RuntimeException(sprintf('Could not write the \'%s\' file.', $key));
        }

        return Util\Size::fromContent($content);
    }

    /**
     * {@inheritDoc}
     */
    public function exists($key)
    {
        return apc_exists($this->computePath($key));
    }

    /**
     * {@inheritDoc}
     */
    public function keys()
    {
        $pattern = sprintf('/^%s/', preg_quote($this->prefix));
        $cachedKeys = new \APCIterator('user', $pattern, APC_ITER_NONE);

        if (null === $cachedKeys) {
            throw new \RuntimeException('Could not get the keys.');
        }

        $keys = array();
        foreach ($cachedKeys as $key => $value) {
            $keys[] = preg_replace($pattern, '', $key);
        }
        sort($keys);

        return $keys;
    }

    /**
     * {@inheritDoc}
     */
    public function mtime($key)
    {
        $this->assertExists($key);

        $pattern = sprintf('/^%s/', preg_quote($this->prefix . $key));
        $cachedKeys = iterator_to_array(new \APCIterator('user', $pattern, APC_ITER_MTIME));

        return $cachedKeys[$this->computePath($key)]['mtime'];
    }

    /**
     * {@inheritDoc}
     */
    public function checksum($key)
    {
        $this->assertExists($key);

        return Util\Checksum::fromContent($this->read($key));
    }

    /**
     * {@inheritDoc}
     */
    public function delete($key)
    {
        $this->assertExists($key);

        $result = apc_delete($this->computePath($key));

        if (false === $result) {
            throw new \RuntimeException(sprintf('Could not delete the \'%s\' file.', $key));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        $this->assertExists($sourceKey);

        if ($this->exists($targetKey)) {
            throw new Exception\UnexpectedFile($targetKey);
        }

        try {
            // TODO: this probably allows for race conditions...
            $this->write($targetKey, $this->read($sourceKey));
            $this->delete($sourceKey);
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf(
                'Could not rename the "%s" file to "%s".',
                $sourceKey,
                $targetKey
            ));
        }
    }

    /**
     * Computes the path for the given key
     *
     * @param string $key
     * @return string
     */
    public function computePath($key)
    {
        return $this->prefix . $key;
    }

    private function assertExists($key)
    {
        if (!$this->exists($key)) {
            throw new Exception\FileNotFound($key);
        }
    }
}
