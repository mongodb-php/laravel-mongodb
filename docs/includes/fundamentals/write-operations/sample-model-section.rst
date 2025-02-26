Sample Model
~~~~~~~~~~~~

The operations in this guide reference the following Eloquent
model class:

.. literalinclude:: /includes/fundamentals/write-operations/Concert.php
   :language: php
   :dedent:
   :caption: Concert.php

.. tip::

   The ``$fillable`` attribute lets you use Laravel mass assignment for insert
   operations. To learn more about mass assignment, see
   :ref:`laravel-model-mass-assignment` in the Eloquent Model Class
   documentation.

   The ``$casts`` attribute instructs Laravel to convert attributes to common
   data types. To learn more, see `Attribute Casting <https://laravel.com/docs/{+laravel-docs-version+}/eloquent-mutators#attribute-casting>`__
   in the Laravel documentation.
