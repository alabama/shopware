<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Area;

use Shopware\Area\Event\AreaBasicLoadedEvent;
use Shopware\Area\Event\AreaDetailLoadedEvent;
use Shopware\Area\Loader\AreaBasicLoader;
use Shopware\Area\Loader\AreaDetailLoader;
use Shopware\Area\Searcher\AreaSearcher;
use Shopware\Area\Struct\AreaBasicCollection;
use Shopware\Area\Struct\AreaDetailCollection;
use Shopware\Area\Struct\AreaSearchResult;
use Shopware\Area\Writer\AreaWriter;
use Shopware\Context\Struct\TranslationContext;
use Shopware\Search\AggregationResult;
use Shopware\Search\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AreaRepository
{
    /**
     * @var AreaDetailLoader
     */
    protected $detailLoader;
    /**
     * @var AreaBasicLoader
     */
    private $basicLoader;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var AreaSearcher
     */
    private $searcher;

    /**
     * @var AreaWriter
     */
    private $writer;

    public function __construct(
        AreaBasicLoader $basicLoader,
        EventDispatcherInterface $eventDispatcher,
        AreaSearcher $searcher,
        AreaWriter $writer,
        AreaDetailLoader $detailLoader
    ) {
        $this->basicLoader = $basicLoader;
        $this->eventDispatcher = $eventDispatcher;
        $this->searcher = $searcher;
        $this->writer = $writer;
        $this->detailLoader = $detailLoader;
    }

    public function readDetail(array $uuids, TranslationContext $context): AreaDetailCollection
    {
        $collection = $this->detailLoader->load($uuids, $context);

        $this->eventDispatcher->dispatch(
            AreaDetailLoadedEvent::NAME,
            new AreaDetailLoadedEvent($collection, $context)
        );

        return $collection;
    }

    public function read(array $uuids, TranslationContext $context): AreaBasicCollection
    {
        $collection = $this->basicLoader->load($uuids, $context);

        $this->eventDispatcher->dispatch(
            AreaBasicLoadedEvent::NAME,
            new AreaBasicLoadedEvent($collection, $context)
        );

        return $collection;
    }

    public function search(Criteria $criteria, TranslationContext $context): AreaSearchResult
    {
        /** @var AreaSearchResult $result */
        $result = $this->searcher->search($criteria, $context);

        $this->eventDispatcher->dispatch(
            AreaBasicLoadedEvent::NAME,
            new AreaBasicLoadedEvent($result, $context)
        );

        return $result;
    }

    public function aggregate(Criteria $criteria, TranslationContext $context): AggregationResult
    {
        $result = $this->searcher->aggregate($criteria, $context);

        return $result;
    }

    public function write(): void
    {
        $this->writer->write();
    }
}