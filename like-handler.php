<?php

namespace App\Controllers;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Cookie;

class LikeController extends Controller
{
    protected const HL_BLOCK_COUNTERS_ID = 15;
    protected const HL_BLOCK_GUEST_LIKES_ID = 16;
    protected const COOKIE_NAME = 'guest_hash';
    protected const COOKIE_LIFETIME = 31536000;

    public function configureActions(): array
    {
        return [
            'like' => [
                'prefilters' => [
                    new ActionFilter\HttpMethod([
                        ActionFilter\HttpMethod::METHOD_POST
                    ]),
                    new ActionFilter\Csrf(),
                ],
            ],
        ];
    }

    protected function validateParams($elementId, $likeAction): bool
    {
        if (!ctype_digit((string)$elementId) || (int)$elementId <= 0) {
            $this->addError(new Error('Некорректный ID элемента'));
            return false;
        }

        if (!in_array($likeAction, ['like', 'unlike'], true)) {
            $this->addError(new Error('Некорректное действие'));
            return false;
        }

        return true;
    }

    protected function getGuestHash(): string
    {
        $request = Application::getInstance()
            ->getContext()
            ->getRequest();

        $hash = $request->getCookie(self::COOKIE_NAME);

        if ($hash && preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return $hash;
        }

        $hash = bin2hex(random_bytes(16));

        $cookie = new Cookie(
            self::COOKIE_NAME,
            $hash,
            time() + self::COOKIE_LIFETIME
        );

        $cookie->setHttpOnly(true);
        $cookie->setPath('/');

        Application::getInstance()
            ->getContext()
            ->getResponse()
            ->addCookie($cookie);

        return $hash;
    }

    protected function getHlDataClass(int $hlBlockId): string
    {
        Loader::includeModule('highloadblock');

        $hlBlock = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlBlockId)
            ->fetch();

        if (!$hlBlock) {
            throw new \RuntimeException('Highload-блок не найден');
        }

        return \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlBlock)
            ->getDataClass();
    }

    public function likeAction($elementId, $likeAction)
    {
        if (!$this->validateParams($elementId, $likeAction)) {
            return null;
        }

        $elementId = (int)$elementId;
        $guestHash = $this->getGuestHash();

        try {
            $countersClass = $this->getHlDataClass(
                self::HL_BLOCK_COUNTERS_ID
            );

            $guestLikesClass = $this->getHlDataClass(
                self::HL_BLOCK_GUEST_LIKES_ID
            );
        } catch (\RuntimeException $e) {
            $this->addError(new Error($e->getMessage()));
            return null;
        }

        $guestLike = $guestLikesClass::getList([
            'filter' => [
                '=UF_ELEMENT_ID' => $elementId,
                '=UF_GUEST_HASH' => $guestHash,
            ],
            'select' => ['ID'],
        ])->fetch();

        $alreadyLiked = (bool)$guestLike;

        if ($likeAction === 'like' && $alreadyLiked) {
            $this->addError(new Error('Вы уже лайкали этот элемент'));
            return null;
        }

        if ($likeAction === 'unlike' && !$alreadyLiked) {
            $this->addError(new Error('Вы ещё не лайкали этот элемент'));
            return null;
        }

        $counter = $countersClass::getList([
            'filter' => [
                '=UF_ELEMENT_ID' => $elementId
            ],
            'select' => [
                'ID',
                'UF_LIKES_COUNT'
            ],
        ])->fetch();

        if ($counter) {
            $newCount = (int)$counter['UF_LIKES_COUNT'];

            if ($likeAction === 'like') {
                $newCount++;
            } else {
                $newCount = max(0, $newCount - 1);
            }

            $result = $countersClass::update(
                $counter['ID'],
                [
                    'UF_LIKES_COUNT' => $newCount,
                ]
            );
        } else {
            $newCount = $likeAction === 'like' ? 1 : 0;

            $result = $countersClass::add([
                'UF_ELEMENT_ID' => $elementId,
                'UF_LIKES_COUNT' => $newCount,
            ]);
        }

        if (!$result->isSuccess()) {
            $this->addErrors($result->getErrors());
            return null;
        }

        if ($likeAction === 'like') {
            $relationResult = $guestLikesClass::add([
                'UF_ELEMENT_ID' => $elementId,
                'UF_GUEST_HASH' => $guestHash,
            ]);
        } else {
            $relationResult = $guestLikesClass::delete(
                $guestLike['ID']
            );
        }

        if (!$relationResult->isSuccess()) {
            $this->addErrors($relationResult->getErrors());
            return null;
        }

        return [
            'success' => true,
            'elementId' => $elementId,
            'action' => $likeAction,
            'liked' => $likeAction === 'like',
            'count' => $newCount,
        ];
    }
}
