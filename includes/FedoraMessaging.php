<?php

use MediaWiki\MediaWikiServices;
use Yosymfony\Toml\Toml;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Ramsey\Uuid\Uuid;


class FedoraMessaging {

    public static function onSave(
        MediaWiki\Revision\RenderedRevision $renderedRevision,
        MediaWiki\User\UserIdentity $user,
        CommentStoreComment $summary,
        $flags,
        Status $hookStatus
    ) {
        $mwconfig = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'FedoraMessaging' );
        $fmConfigPath = $mwconfig->get( 'FedoraMessagingConfigFile' );
        $connection = FedoraMessaging::connect($fmConfigPath);
        $channel = $connection->channel();

        //$metadata = $renderedRevision->getSlotParserOutput("main", ["generate-html" => false]);
        //print_r($metadata);
        $revisionRecord = $renderedRevision->getRevision();
        $title = $revisionRecord->getPage();
        if ( $title->getNsText() ) {
            $titletext = $title->getNsText() . ":" . $title->getText();
        } else {
            $titletext = $title->getText();
        }
        $prevRevId = $revisionRecord->getParentId();
        $curRevId = $prevRevId + 1; // We don't know that! But it's not committed yet so we can only assume.
        $url = $title->getFullURL('diff=' . $curRevId . '&oldid=' . $prevRevId);
        $username = strtolower($user->getName());

        $body = json_encode(array(
            "title" => $titletext,
            "user" => $username,
            // This does not seem to work.
            "minor_edit" => $revisionRecord->isMinor(),
            // This does not work:
            "revision" => $revisionRecord,
            "url" => $url,
            "diff_url" => $url,
            "page_url" => $title->getFullURL(),
            # Make sure we can send the summary without risk these days:
            # https://fedorahosted.org/fedora-infrastructure/ticket/3738#comment:7
            "summary" => $summary->text
            #"text" => $text,  # We *could* send this, but it's a lot of spam.
            # TODO - flags?
            # TODO - status?
        ));

        $topic = "wiki.article.edit";
        $headers = array(
            "fedora_messaging_schema" => "wiki.article.edit.v1",
            "fedora_messaging_severity" => 20,
            "fedora_messaging_user_$username" => true,
            "sent-at" => date("c"),
        );
        $message_id = Uuid::uuid4()->toString();
        $properties = array(
            'content_type' => "application/json",
            'content_encoding' => "utf-8",
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'application_headers' => new AMQPTable($headers),
            'message_id' => $message_id,
            'priority' => $config["publish_priority"] ?? 0
        );
        $message = new AMQPMessage($body, $properties);
        $exchange = $config["publish_exchange"] ?? "amq.topic";
        $topic_prefix = $config["topic_prefix"] ? $config["topic_prefix"] . "." : "";

        $channel->basic_publish($message, $exchange, $topic_prefix . $topic);

        $channel->close();
        $connection->close();
    }

    public static function connect($configPath) {
        $config = Toml::ParseFile($configPath);
        $amqp_url = parse_url($config["amqp_url"]);

        $connectionConfig = new AMQPConnectionConfig();
        $connectionConfig->setIsSecure($amqp_url["scheme"] == "amqps");
        $connectionConfig->setHost($amqp_url["host"] ?? "localhost");
        $connectionConfig->setPort($amqp_url["port"] ?? $connectionConfig->isSecure() ? 5671 : 5672);
        $connectionConfig->setUser($amqp_url["user"] ?? "guest");
        $connectionConfig->setPassword($amqp_url["pass"] ?? "guest");
        $connectionConfig->setVhost(urldecode(substr($amqp_url["path"] ?? "/%2F", 1)));
        if (isset($config["client_properties"]["app"])) {
            $connectionConfig->setConnectionName($config["client_properties"]["app"]);
        } else {
            $connectionConfig->setConnectionName("PHP Fedora Messaging");
        }
        if ($connectionConfig->isSecure()) {
            $connectionConfig->setSslCaCert($config["tls"]["ca_cert"]);
            $connectionConfig->setSslCert($config["tls"]["certfile"]);
            $connectionConfig->setSslKey($config["tls"]["keyfile"]);
        }

        return AMQPConnectionFactory::create($connectionConfig);
    }
}
