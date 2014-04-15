#!/bin/bash
rm stdout.log stderr.log
DB_NAME=mzk_test2
SOLR_URL=http://localhost:8983/solr/mzk_test2
PARAMS="--config.Mongo.database=$DB_NAME"
PARAMS+=" --config.Solr.update_url=$SOLR_URL/update"
TIMEOUT=60
for SOURCE in MZK01-VDK MZK01-VUFIND MZK03-VDK MZK03-RAJHRAD MZK03-DACICE MZK03-ZNOJMO MZK03-TREBOVA MZK04
do
  echo $SOURCE
  timeout $TIMEOUT php harvest.php $PARAMS --source=$SOURCE --all 1>> stdout.log 2>> error.log
  php manage.php $PARAMS --func=updatesolr --source=$SOURCE --all 1>> stdout.log 2>> error.log
done
