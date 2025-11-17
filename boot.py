# boot.py
#
# 1. We patch eventlet here, FIRST, before anything else is loaded.
#
import eventlet
eventlet.monkey_patch()

#
# 2. Now that the patch is active, we can safely import
#    the 'app' object from your server file.
#
from ws_server import app

#
# 3. We provide the 'application' object that Passenger
#    is looking for. This is just the Flask app.
#
application = app