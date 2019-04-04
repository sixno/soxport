# soxport

这是一个端口映射工具，参考了[augushong / workerman-port-mapping](https://gitee.com/augushong/workerman-port-mapping)，对原作进行了代码整理和优化精简，使用此工具可实现的端口映射和内网穿透（需要一台公网主机）。

Config文件夹下是配置示例，此工具只在Linux测试过，windows下没做过测试亦无需求。

此工具使用的先决条件是掌握Workerman的安装及运行方法。

配置请按照示例填满，涉及端口的配置项保证端口各不相同且避免与其他软件的端口冲突问题即可。

做内网穿透时，配置中server_addr请填写服务端公网IP，client_addr一般是127.0.0.1，但也可以填写局域网IP，为内网中其他机器提供穿透条件。

服务端运行 php server.php start -d

客户端运行 php client.php start -d
