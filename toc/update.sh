#!/bin/bash
mv toc.xml toc.xml.old && wget "http://www.obalkyknih.cz/api/toc.xml" -O toc.xml && ./toc.php
