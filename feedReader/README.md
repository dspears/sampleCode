# Example Code from my Blog RSS Feed Aggregation App

## File: app/Classes/HomePageHelper.php

This file contains the code for performing queries of either MySQL or ElasticSearch.  If it's a search for freeform text, then ElasticSearch is used, otherwise MySQL is used.  (MySQL's fulltext search capabilities are no match for ElasticSearch).

## File: resources/views/sideMenu.js

This file contains Vue components for rendering the left navigation sidebar (for registered/authenticated users).
