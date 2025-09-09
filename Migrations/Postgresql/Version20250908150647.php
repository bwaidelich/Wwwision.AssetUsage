<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQL100Platform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250908150647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wwwision_assetusage table for the Wwwision.AssetUsage package (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQL100Platform,
            'Migration can only be executed safely on "postgresql".'
        );

        $this->addSql('
            CREATE TABLE wwwision_assetusage (
              asset_id VARCHAR(40) NOT NULL,
              node_id VARCHAR(255) NOT NULL,
              workspace VARCHAR(255) NOT NULL,
              dimensionshash VARCHAR(32) NOT NULL,
              nodetype VARCHAR(255) NOT NULL,
              CONSTRAINT wwwision_assetusage_unique UNIQUE (asset_id, node_id, workspace, dimensionshash)
            )
        ');

        $this->addSql('
            CREATE INDEX wwwision_assetusage_asset_node_idx
              ON wwwision_assetusage (asset_id, node_id)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQL100Platform,
            'Migration can only be executed safely on "postgresql".'
        );

        $this->addSql('DROP TABLE wwwision_assetusage');
    }
}
