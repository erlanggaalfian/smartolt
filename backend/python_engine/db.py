import os
import re
import base64
import hashlib
import pymysql
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import padding

def load_env():
    """
    Load environment variables from .env file located in root directory
    """
    base_dir = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    env_path = os.path.join(base_dir, '.env')
    if os.path.exists(env_path):
        with open(env_path, 'r') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                parts = line.split('=', 1)
                if len(parts) == 2:
                    key = parts[0].strip()
                    val = parts[1].strip().strip('"\'')
                    if key not in os.environ:
                        os.environ[key] = val

# Automatically load environment variables
load_env()

def get_db_connection():
    """
    Establish and return a new PyMySQL database connection
    """
    host = os.getenv('DB_HOST', 'localhost')
    port = int(os.getenv('DB_PORT', 3306))
    user = os.getenv('DB_USER', 'smartoltuser')
    password = os.getenv('DB_PASSWORD', '')
    db = os.getenv('DB_NAME', 'smartoltdb')
    
    return pymysql.connect(
        host=host,
        port=port,
        user=user,
        password=password,
        database=db,
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor
    )

def decrypt_password(ciphertext_b64: str) -> str:
    """
    Decrypt OLT password using AES-256-CBC (replicates PHP decrypt_password function)
    """
    if not ciphertext_b64:
        return ""
    try:
        key = os.getenv('APP_KEY', 'some-default-long-secret-key-change-me')
        encryption_key = hashlib.sha256(key.encode('utf-8')).digest()
        
        # Base64 decode
        data = base64.b64decode(ciphertext_b64.encode('utf-8'))
        
        iv_length = 16  # AES block size
        if len(data) <= iv_length:
            return ciphertext_b64
            
        iv = data[:iv_length]
        ciphertext = data[iv_length:]
        
        cipher = Cipher(algorithms.AES(encryption_key), modes.CBC(iv), backend=default_backend())
        decryptor = cipher.decryptor()
        decrypted_padded = decryptor.update(ciphertext) + decryptor.finalize()
        
        # Unpad PKCS7
        unpacker = padding.PKCS7(128).unpadder()
        decrypted = unpacker.update(decrypted_padded) + unpacker.finalize()
        
        return decrypted.decode('utf-8')
    except Exception:
        # Fallback to returning input if not encrypted or decryption fails
        return ciphertext_b64

def extract_customer_name(desc: str) -> str:
    """
    Extract clean customer name from structured OLT description.
    Input:  "name_PelangganTiga_zone_ZONA_descr_None_odb_ODB01A0003_authd_20260826"
    Output: "PelangganTiga"
    Uses SAME regex as PHP extract_customer_name() in backend/db.php.
    """
    if not desc:
        return ""
    # Format baru: name di awal — non-greedy match sampai _zone/_descr/_odb/_authd/_contact_
    m = re.match(r'^name_(.*?)(?:_(?:zone|descr|odb|authd|contact)_|$)', desc, re.I)
    if m:
        return m.group(1).strip()
    # Format legacy: name di tengah/belakang
    m = re.search(r'_name_(.*?)(?:_(?:zone|descr|odb|authd|contact|name)_|$)', desc, re.I)
    if m:
        return m.group(1).strip()
    # Bukan format terstruktur — kembalikan apa adanya
    return desc.strip()

def parse_structured_description(desc: str) -> dict:
    """
    Parse OLT structured description string to clean dict format
    """
    res = {
        'zone': None,
        'address': None,
        'splitter': None,
        'contact': None,
        'external_id': None
    }
    if not desc:
        return res
        
    m = re.search(r'zone_([^_]+)', desc, re.I)
    if m:
        val = m.group(1).replace('_', ' ').strip()
        if val and val != 'None':
            res['zone'] = val
        
    m = re.search(r'descr_(.*?)(_odb_|_authd_|_contact_|_name_|$)', desc, re.I)
    if m:
        val = m.group(1).replace('_', ' ').strip()
        if val and val != 'None':
            res['address'] = val
        
    m = re.search(r'_odb_(.*?)(?:_authd|_auth|_extid|$)', desc, re.I)
    if m:
        splitter_val = m.group(1).rstrip('_').replace('_', ' ').strip()
        # Buang prefix "ODP " berlebih (OLT kadang tulis odb_ODP_TGR-... jadi "ODP TGR-...")
        splitter_val = re.sub(r'^ODP\s+', '', splitter_val, flags=re.I)
        # Buang GPS coordinates contamination (OLT kadang append " lat -X.XXX long XXX.XXX")
        splitter_val = re.sub(r'\s*lat\s+-?\d+\.?\d*\s+long\s+-?\d+\.?\d*.*$', '', splitter_val, flags=re.I)
        if splitter_val and splitter_val != 'None':
            res['splitter'] = splitter_val
        
    m = re.search(r'contact_(.*?)(_descr_|_authd_|_odb_|_name_|$)', desc, re.I)
    if m:
        val = m.group(1).replace('_', ' ').strip()
        if val and val != 'None':
            res['contact'] = val

    m = re.search(r'_extid_([^_]+)', desc, re.I)
    if m:
        val = m.group(1).strip()
        if val and val != 'None':
            res['external_id'] = val

    return res

def get_allowed_olt_ids(role: str, user_id: int) -> list:
    """
    Get OLT IDs allowed for the logged in user based on role and user_id.
    """
    if not role:
        return []
        
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            if role == 'superadmin':
                cursor.execute("SELECT id FROM olts")
                rows = cursor.fetchall()
                return [r['id'] for r in rows]
            else:
                cursor.execute("SELECT olt_id FROM user_olts WHERE user_id = %s", (user_id,))
                rows = cursor.fetchall()
                return [r['olt_id'] for r in rows]
    finally:
        conn.close()

def has_olt_access(olt_id: int, role: str, user_id: int) -> bool:
    """
    Check if the user has access to a specific OLT.
    """
    if not role:
        return False
    if role == 'superadmin':
        return True
    allowed = get_allowed_olt_ids(role, user_id)
    return olt_id in allowed

