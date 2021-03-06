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

namespace Shopware\Bundle\StoreFrontBundle\Gateway\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Shopware\Bundle\StoreFrontBundle\Struct;
use Shopware\Bundle\StoreFrontBundle\Gateway;

/**
 * @category  Shopware
 * @package   Shopware\Bundle\StoreFrontBundle\Gateway\DBAL
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class ConfiguratorGateway implements Gateway\ConfiguratorGatewayInterface
{
    /**
     * @var Hydrator\ConfiguratorHydrator
     */
    private $configuratorHydrator;

    /**
     * The FieldHelper class is used for the
     * different table column definitions.
     *
     * This class helps to select each time all required
     * table data for the store front.
     *
     * Additionally the field helper reduce the work, to
     * select in a second step the different required
     * attribute tables for a parent table.
     *
     * @var FieldHelper
     */
    private $fieldHelper;

    /**
     * @var \Shopware\Bundle\StoreFrontBundle\Gateway\MediaGatewayInterface
     */
    private $mediaGateway;

    /**
     * @param Connection $connection
     * @param FieldHelper $fieldHelper
     * @param Hydrator\ConfiguratorHydrator $configuratorHydrator
     * @param \Shopware\Bundle\StoreFrontBundle\Gateway\MediaGatewayInterface $mediaGateway
     */
    public function __construct(
        Connection $connection,
        FieldHelper $fieldHelper,
        Hydrator\ConfiguratorHydrator $configuratorHydrator,
        Gateway\MediaGatewayInterface $mediaGateway
    ) {
        $this->connection = $connection;
        $this->configuratorHydrator = $configuratorHydrator;
        $this->fieldHelper = $fieldHelper;
        $this->mediaGateway = $mediaGateway;
    }

    /**
     * @inheritdoc
     */
    public function get(Struct\BaseProduct $product, Struct\ShopContextInterface $context)
    {
        $query = $this->getQuery();

        $query->addSelect($this->fieldHelper->getConfiguratorSetFields())
            ->addSelect($this->fieldHelper->getConfiguratorGroupFields())
            ->addSelect($this->fieldHelper->getConfiguratorOptionFields())
        ;

        $this->fieldHelper->addConfiguratorTranslation(
            $query,
            $context
        );

        $query->where('products.id = :id')
            ->setParameter(':id', $product->getId());

        /**@var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $query->execute();

        $data = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $this->configuratorHydrator->hydrate($data);
    }

    /**
     * @inheritdoc
     */
    public function getConfiguratorMedia(Struct\BaseProduct $product, Struct\ShopContextInterface $context)
    {
        $subQuery = $this->connection->createQueryBuilder();

        $subQuery->select('image.media_id')
            ->from('s_articles_img', 'image')
            ->innerJoin('image', 's_article_img_mappings', 'mapping', 'mapping.image_id = image.id')
            ->innerJoin('mapping', 's_article_img_mapping_rules', 'rules', 'rules.mapping_id = mapping.id')
            ->where('image.articleID = product.id')
            ->andWhere('rules.option_id = optionRelation.option_id')
            ->orderBy('image.position')
            ->setMaxResults(1)
        ;

        $query = $this->connection->createQueryBuilder();

        $query->select(
            [
            'optionRelation.option_id',
            '(' . $subQuery->getSQL() . ') as media_id'
            ]
        );

        $query->from('s_articles', 'product')
            ->innerJoin(
                'product',
                's_article_configurator_set_option_relations',
                'optionRelation',
                'product.configurator_set_id = optionRelation.set_id'
            );

        $query->where('product.id = :articleId');

        $query->groupBy('optionRelation.option_id');

        $query->setParameter(':articleId', $product->getId());

        /**@var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $query->execute();

        $data = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);
        $data = array_filter($data);

        $media = $this->mediaGateway->getList($data, $context);

        $result = [];
        foreach ($data as $optionId => $mediaId) {
            if (!isset($media[$mediaId])) {
                continue;
            }
            $result[$optionId] = $media[$mediaId];
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function getProductCombinations(Struct\BaseProduct $product)
    {
        $query = $this->connection->createQueryBuilder();

        $query->select([
            'relations.option_id',
            "GROUP_CONCAT(DISTINCT assignedRelations.option_id, '' SEPARATOR '|') as combinations"
        ]);

        $query->from('s_article_configurator_option_relations', 'relations');

        $query->innerJoin(
            'relations',
            's_articles_details',
            'variant',
            'variant.id = relations.article_id
             AND variant.articleID = :articleId
             AND variant.active = 1'
        );

        $query->innerJoin(
            'variant',
            's_articles',
            'product',
            'product.id = variant.articleID AND
            (product.laststock * variant.instock) >= (product.laststock * variant.minpurchase)'
        );

        $query->leftJoin(
            'relations',
            's_article_configurator_option_relations',
            'assignedRelations',
            'assignedRelations.article_id = relations.article_id
             AND assignedRelations.option_id != relations.option_id'
        );

        $query->groupBy('relations.option_id');

        $query->setParameter(':articleId', $product->getId());

        /**@var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $query->execute();

        $data = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);

        foreach ($data as &$row) {
            $row = explode('|', $row);
        }

        return $data;
    }

    /**
     * @return QueryBuilder
     */
    private function getQuery()
    {
        $query = $this->connection->createQueryBuilder();

        $query->from(
            's_article_configurator_sets',
            'configuratorSet'
        );

        $query->innerJoin(
            'configuratorSet',
            's_articles',
            'products',
            'products.configurator_set_id = configuratorSet.id'
        );

        $query->innerJoin(
            'configuratorSet',
            's_article_configurator_set_group_relations',
            'groupRelation',
            'groupRelation.set_id = configuratorSet.id'
        );

        $query->innerJoin(
            'groupRelation',
            's_article_configurator_groups',
            'configuratorGroup',
            'configuratorGroup.id = groupRelation.group_id'
        );

        $query->innerJoin(
            'configuratorSet',
            's_article_configurator_set_option_relations',
            'optionRelation',
            'optionRelation.set_id = configuratorSet.id'
        );

        $query->innerJoin(
            'optionRelation',
            's_article_configurator_options',
            'configuratorOption',
            'configuratorOption.id = optionRelation.option_id
             AND
             configuratorOption.group_id = configuratorGroup.id'
        );

        $query->addOrderBy('configuratorGroup.position')
            ->addOrderBy('configuratorGroup.name')
            ->addOrderBy('configuratorOption.position')
            ->addOrderBy('configuratorOption.name');

        $query->groupBy('configuratorOption.id');

        return $query;
    }
}
