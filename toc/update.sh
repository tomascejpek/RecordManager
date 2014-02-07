#!/bin/bash
if [ -e toc.xml ] ; then 
	mv toc.xml toc.xml.old 
fi
wget "http://www.obalkyknih.cz/api/toc.xml" -O toc.xml && ./toc.php
