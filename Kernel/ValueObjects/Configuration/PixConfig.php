<?php

namespace PlugHacker\PlugCore\Kernel\ValueObjects\Configuration;

use PlugHacker\PlugCore\Kernel\Abstractions\AbstractValueObject;

class PixConfig extends AbstractValueObject
{
    /** @var bool */
    private $enabled;

    /** @var string */
    private $title;

    /**
     * @var int
     */
    private $expirationQrCode;

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @param bool $enabled
     * @return PixConfig
     */
    public function setEnabled($enabled)
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param string $title
     * @return PixConfig
     */
    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    /**
     * @return int
     */
    public function getExpirationQrCode()
    {
        return $this->expirationQrCode;
    }

    /**
     * @param int $expirationQrCode
     * @return PixConfig
     */
    public function setExpirationQrCode($expirationQrCode)
    {
        $this->expirationQrCode = $expirationQrCode;
        return $this;
    }

    /**
     * To check the structural equality of value objects,
     * this method should be implemented in this class children.
     *
     * @param  $object
     * @return bool
     */
    protected function isEqual($object)
    {
        return
            $this->enabled === $object->isEnabled() &&
            $this->expirationQrCode === $object->getExpirationQrCode();
    }

    /**
     * Specify data which should be serialized to JSON
     * @link https://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize(): mixed
    {
        return [
            "enabled" => $this->enabled,
            "title" => $this->getTitle(),
            "expirationQrCode" => $this->getExpirationQrCode()
        ];
    }
}
