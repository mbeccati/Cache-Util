<?php

namespace Psr\Cache;

use Predis\Client;
use Predis\Pipeline\PipelineContext;


/**
 * An Predis implementation of the Pool interface.
 */
class PredisPool implements CacheItemPoolInterface
{
    use CachePoolDeferTrait;

    const LOCK_TTL = 10;

    /**
     * The locks that have been acquired
     *
     * @var array
     */
    protected $locks = [];

    /**
     * The Predis client
     *
     * @var \Predis\Client
     */
    protected $predis;

    /**
     * Whether or not stampede protection needs to be enabled
     *
     * @var bool
     */
    protected $stampedeProtection = false;

    /**
     * The class constructor
     *
     * @param Client $predis
     */
    public function __construct(Client $predis)
    {
        $this->predis = $predis;
    }

    /**
     * Enable or disable stampede protection
     *
     * @param $stampedeProtection bool
     */
    public function setStampedeProtection($stampedeProtection)
    {
        $this->stampedeProtection = $stampedeProtection;
    }

    /**
     * Get the Predis client and connect, if necessary
     *
     * @return Client
     */
    protected function getRedis()
    {
        if (!$this->predis->isConnected()) {
            $this->predis->connect();
        }

        return $this->predis;
    }

    /**
     * Get the current DateTime
     *
     * @return \DateTime
     */
    protected function getNow()
    {
        return new \DateTime;
    }


    /**
     * Serialize value and expiration time
     *
     * @param $value
     * @param $ttd
     * @return string
     */
    protected function serialize($value, $ttd)
    {
        return serialize([
            'v' => $value,
            't' => $ttd,
        ]);
    }

    /**
     * Unserialize data coming from the cache
     *
     * @param $data
     * @return array|null
     */
    protected function unserialize($data)
    {
        if ($data) {
            $data = @unserialize($data);

            // Make sure the data is coming from the cache pool
            if (is_array($data) && count($data) == 2 && array_key_exists('v', $data) && array_key_exists('t', $data)) {
                return [
                    'value' => $data['v'],
                    'ttd' => $data['t'],
                ];
            }
        }

        return null;
    }

    /**
     * Acquire a non-blocking lock on Redis
     *
     * @param $key
     * @return bool
     */
    protected function lock($key)
    {
        $lock = '*lock*'.$key;

        $predis = $this->getRedis();

        if ($predis->setnx($lock, 1)) {
            $predis->expire($lock, self::LOCK_TTL);

            $this->locks[$key] = true;

            return true;
        }

        return false;
    }

    /**
     * Unlock a previously locked key
     *
     * @param $key
     * @param PipelineContext $pipeline
     * @return bool
     */
    protected function unlock($key, PipelineContext $pipeline = null)
    {
        if (isset($this->locks[$key])) {

            $lock = '*lock*'.$key;

            $predis = $pipeline ? $pipeline : $this->getRedis();

            $predis->del($lock);

            unset($this->locks[$key]);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @todo Add stampeded protection to new items. Should it be a blocking lock?
     *
     */
    public function getItem($key)
    {
        $predis = $this->getRedis();

        $data = $this->unserialize($predis->get($key));

        if (empty($data)) {
            $data = [
                'value' => null,
                'ttd' => null,
                'hit' => false,
            ];
        } else {
            $data['hit'] = !isset($data['ttd']) || $data['ttd'] > $this->getNow();

            if (!$data['hit'] && $this->stampedeProtection) {
                if (!$this->lock($key)) {
                    $data['hit'] = true;
                }
            }
        }

        return new PredisCacheItem($this, $key, $data);    }

    /**
     * {@inheritdoc}
     *
     * @todo Add pipelining
     *
     */
    public function getItems(array $keys = array())
    {
        $collection = [];
        foreach ($keys as $key) {
            $collection[$key] = $this->getItem($key);
        }
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return (bool)$this->getRedis()->flushDb();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys)
    {
        $pipeline = $this->getRedis()->pipeline();

        foreach ($keys as $key) {
            $pipeline->del($key);

            $this->unlock($key, $pipeline);
        }

        $pipeline->execute();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $items)
    {
        $pipeline = $this->getRedis()->pipeline();

        /** @var \Psr\Cache\CacheItemInterface $item  */
        foreach ($items as $item) {
            $key = $item->getKey();
            $pipeline->set($key, $this->serialize(
                $item->getRawValue(),
                $item->getExpiration()
            ));

            $this->unlock($key, $pipeline);
        }

        $pipeline->execute();

        return true;
    }
}
