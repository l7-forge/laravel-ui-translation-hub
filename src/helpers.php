<?php

if (! function_exists('__t')) {
      /**
       * Übersetzt den übergebenen String anhand des Hex-Index.
       */
      function __t($key) {
            $hexKey = bin2hex($key);
            return app('custom_translator')->translate($hexKey);
        }
  }
