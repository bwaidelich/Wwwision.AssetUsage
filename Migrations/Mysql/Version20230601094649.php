<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230601094649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wwwision_assetusage table for the Wwwision.AssetUsage package';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof MySqlPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE `wwwision_assetusage` (
          `asset_id` VARCHAR(40) NOT NULL,
          `node_id` VARCHAR(255) NOT NULL,
          `workspace` VARCHAR(255) NOT NULL,
          `dimensionshash` VARCHAR(32) NOT NULL,
          `nodetype` VARCHAR(255) NOT NULL,
          UNIQUE KEY `unique` (`asset_id`,`node_id`,`workspace`,`dimensionshash`),
          KEY `asset_node` (`asset_id`,`node_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof MySqlPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE `wwwision_assetusage`');
    }
}
