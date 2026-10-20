import sys,json,http.client
connection=http.client.HTTPConnection(sys.argv[1],8080,timeout=10)
def request(path,data=None):
    headers={'Accept':'application/json'}
    body=None if data is None else json.dumps(data).encode()
    if body is not None:headers['Content-Type']='application/json'
    connection.request('GET' if body is None else 'POST',path,body,headers)
    r=connection.getresponse()
    raw=r.read();media=r.headers.get('Content-Type','').split(';')[0];assert media=='application/json' or media.endswith('+json'),(path,r.status,raw)
    return r.status,json.loads(raw)
assert request('/hello')==(200,{'message':'Hello, world'})
for i in [1,57,997,10000]:
    assert request('/users/'+str(i))==(200,{'id':i,'name':f'User {i:05}','email':f'user{i:05}@example.test','age':18+i%103})
assert request('/users/10001')[0]==404
valid={'name':'Ada Example','email':'ada@example.test','age':36}
for data in [valid,dict(valid,name='x',age=18),dict(valid,name='x'*100,age=120),dict(valid,name='  Ada  '),dict(valid,name=' ')]:
    assert request('/users',data)==(201,data),(data,request('/users',data))
for data in [{},dict(valid,name=''),dict(valid,name='x'*101),dict(valid,name=' '*101),dict(valid,name=7),dict(valid,email='bad'),dict(valid,age=17),dict(valid,age=121),dict(valid,age='36'),dict(valid,age=36.0),dict(valid,age=True),dict(valid,age=None)]:
    status,body=request('/users',data)
    assert status==422,(data,status,body)
status,metadata=request('/__bench');assert status==200
if sys.argv[1]=='elysia':
    assert metadata['bun']=='1.4.2' and metadata['production'] and metadata['workers']==2 and metadata['reuse_port'],metadata
else:
    assert metadata['php'].startswith('8.4.'),metadata
assert metadata['debug'] is False,metadata
if sys.argv[1]!='elysia':assert metadata['classmap_authoritative'],metadata
if sys.argv[1] in ['octane','fpm']:assert metadata['config_cached'] and metadata['routes_cached'] and metadata['persistent'],metadata
if sys.argv[1]=='symfony':assert metadata['preload'] and metadata['preload_classes']>0,metadata
if sys.argv[1]!='elysia':assert metadata['opcache']=='1'and metadata['timestamps']=='0',metadata
if sys.argv[1]=='webman':assert metadata['workers']==2 and metadata['persistent'] and metadata['event']=='3.1.6',metadata
if sys.argv[1]=='hyperf':assert metadata['workers']==2 and metadata['scan_cacheable'] and metadata['swoole']=='6.2.3',metadata
if sys.argv[1]=='spiral':assert metadata['workers']==2 and metadata['tokenizer_cache'],metadata
connection.close()
print(json.dumps({'ok':True,'metadata':metadata}))
